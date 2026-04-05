#!/usr/bin/env bash
set -euo pipefail

# ------------------------------------------------------------
# Prüfen ob Script als root läuft
# ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
  echo "⚠️ Bitte als root oder mit sudo ausführen."
  exit 1
fi

echo "========================================"
echo "======== XC - Panel Installer =========="
echo "========================================"
echo

LOGFILE="/var/log/xc-setup.log"

# ------------------------------------------------------------
# Domain-Abfrage
# ------------------------------------------------------------
echo "🔹 SSL-Certificate Setup"
echo "---------------------------------------"
read -p "Do you want create a SSL-Certificate? (y/n): " setup_ssl

DOMAIN=""
EMAIL=""
if [[ "$setup_ssl" =~ ^[yY]$ ]]; then
  read -p "Enter Domainname (e.x. panel.example.com): " DOMAIN
  read -p "Enter E-Mail for Let's Encrypt: " EMAIL
  
  if [[ -z "$DOMAIN" ]] || [[ -z "$EMAIL" ]]; then
    echo "⚠️ Domain or E-Mail missing. Skipping SSL-Setup."
    DOMAIN=""
  else
    echo "✅ Creating SSL for $DOMAIN"
  fi
fi
echo

# ------------------------------------------------------------
# 1) Pakete installieren
# ------------------------------------------------------------
echo "🔹 1) Starting package installation..."
PACKAGES=(
  apache2 php-fpm php-xml php-mbstring php-curl php-zip
  ffmpeg streamlink yt-dlp libapache2-mod-fcgid libapache2-mod-xsendfile ufw fuse3
  certbot python3-certbot-apache curl wget unzip git fail2ban
)

apt-get update -y -qq >> "$LOGFILE" 2>&1

for pkg in "${PACKAGES[@]}"; do
  if dpkg -s "$pkg" &>/dev/null; then
    echo "   ✔ $pkg already installed." >> "$LOGFILE"
  else
    echo "   ➤ Install $pkg..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$pkg" >> "$LOGFILE" 2>&1
  fi
done
echo "✅ Package installation finished"
echo

# ------------------------------------------------------------
# 2) PHP-FPM aktivieren und konfigurieren
# ------------------------------------------------------------
echo "🔹 2) Activate PHP-FPM..."
PHP_FPM_SERVICE=$(systemctl list-unit-files | grep -Eo 'php[0-9.]+-fpm\.service' | head -n1 || true)

if [[ -n "$PHP_FPM_SERVICE" ]]; then
  echo "   ✔ Found PHP-FPM: $PHP_FPM_SERVICE"
  
  # PHP-Version ermitteln
  PHP_VERSION=$(echo "$PHP_FPM_SERVICE" | grep -Eo '[0-9]+\.[0-9]+')
  PHP_FPM_CONF="/etc/php/${PHP_VERSION}/fpm/php-fpm.conf"
  PHP_POOL_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
  
  # [global] Konfiguration anpassen
  if [[ -f "$PHP_FPM_CONF" ]]; then
    echo "   ➤ Configure PHP-FPM global settings..."
    
    # Backup erstellen
    cp "$PHP_FPM_CONF" "${PHP_FPM_CONF}.backup"
    
    # events.mechanism auf epoll setzen (beste Performance unter Linux)
    if grep -q "^events.mechanism" "$PHP_FPM_CONF"; then
      sed -i 's/^events.mechanism = .*/events.mechanism = epoll/' "$PHP_FPM_CONF"
    elif grep -q "^;events.mechanism" "$PHP_FPM_CONF"; then
      sed -i 's/^;events.mechanism = .*/events.mechanism = epoll/' "$PHP_FPM_CONF"
    else
      sed -i '/^\[global\]/a events.mechanism = epoll' "$PHP_FPM_CONF"
    fi
    
    # rlimit_files erhöhen (wichtig für viele gleichzeitige Verbindungen)
    if grep -q "^rlimit_files" "$PHP_FPM_CONF"; then
      sed -i 's/^rlimit_files = .*/rlimit_files = 65536/' "$PHP_FPM_CONF"
    elif grep -q "^;rlimit_files" "$PHP_FPM_CONF"; then
      sed -i 's/^;rlimit_files = .*/rlimit_files = 65536/' "$PHP_FPM_CONF"
    else
      sed -i '/^events.mechanism/a rlimit_files = 65536' "$PHP_FPM_CONF"
    fi
    
    echo "   ✔ PHP-FPM global settings configured"
  fi
  
  # Pool-Konfiguration anpassen
  if [[ -f "$PHP_POOL_CONF" ]]; then
    echo "   ➤ Configure PHP-FPM Pool (pm=ondemand, max_children=4000)..."
    
    # Backup erstellen
    cp "$PHP_POOL_CONF" "${PHP_POOL_CONF}.backup"
    
    # listen Konfiguration (Unix Socket)
    sed -i 's|^listen = .*|listen = /run/php/php'"${PHP_VERSION}"'-fpm.sock|' "$PHP_POOL_CONF"
    
    # listen.owner und listen.group
    if grep -q "^listen.owner" "$PHP_POOL_CONF"; then
      sed -i 's/^listen.owner = .*/listen.owner = www-data/' "$PHP_POOL_CONF"
    else
      sed -i '/^listen = /a listen.owner = www-data' "$PHP_POOL_CONF"
    fi
    
    if grep -q "^listen.group" "$PHP_POOL_CONF"; then
      sed -i 's/^listen.group = .*/listen.group = www-data/' "$PHP_POOL_CONF"
    else
      sed -i '/^listen.owner/a listen.group = www-data' "$PHP_POOL_CONF"
    fi
    
    # listen.mode
    if grep -q "^listen.mode" "$PHP_POOL_CONF"; then
      sed -i 's/^listen.mode = .*/listen.mode = 0660/' "$PHP_POOL_CONF"
    elif grep -q "^;listen.mode" "$PHP_POOL_CONF"; then
      sed -i 's/^;listen.mode = .*/listen.mode = 0660/' "$PHP_POOL_CONF"
    else
      sed -i '/^listen.group/a listen.mode = 0660' "$PHP_POOL_CONF"
    fi
    
    # pm auf ondemand setzen
    sed -i 's/^pm = .*/pm = ondemand/' "$PHP_POOL_CONF"
    
    # pm.max_children setzen
    if grep -q "^pm.max_children" "$PHP_POOL_CONF"; then
      sed -i 's/^pm.max_children = .*/pm.max_children = 4000/' "$PHP_POOL_CONF"
    else
      sed -i '/^pm = ondemand/a pm.max_children = 4000' "$PHP_POOL_CONF"
    fi
    
    # pm.process_idle_timeout (wichtig für ondemand!)
    if grep -q "^pm.process_idle_timeout" "$PHP_POOL_CONF"; then
      sed -i 's/^pm.process_idle_timeout = .*/pm.process_idle_timeout = 3s/' "$PHP_POOL_CONF"
    elif grep -q "^;pm.process_idle_timeout" "$PHP_POOL_CONF"; then
      sed -i 's/^;pm.process_idle_timeout = .*/pm.process_idle_timeout = 3s/' "$PHP_POOL_CONF"
    else
      sed -i '/^pm.max_children/a pm.process_idle_timeout = 3s' "$PHP_POOL_CONF"
    fi
    
    # pm.max_requests setzen
    if grep -q "^pm.max_requests" "$PHP_POOL_CONF"; then
      sed -i 's/^pm.max_requests = .*/pm.max_requests = 40000/' "$PHP_POOL_CONF"
    elif grep -q "^;pm.max_requests" "$PHP_POOL_CONF"; then
      sed -i 's/^;pm.max_requests = .*/pm.max_requests = 40000/' "$PHP_POOL_CONF"
    else
      sed -i '/^pm.process_idle_timeout/a pm.max_requests = 40000' "$PHP_POOL_CONF"
    fi
    
    # security.limit_extensions (Security!)
    if grep -q "^security.limit_extensions" "$PHP_POOL_CONF"; then
      sed -i 's|^security.limit_extensions = .*|security.limit_extensions = .php|' "$PHP_POOL_CONF"
    elif grep -q "^;security.limit_extensions" "$PHP_POOL_CONF"; then
      sed -i 's|^;security.limit_extensions = .*|security.limit_extensions = .php|' "$PHP_POOL_CONF"
    else
      echo "security.limit_extensions = .php" >> "$PHP_POOL_CONF"
    fi
    
    # pm.status_path (für Monitoring)
    if grep -q "^pm.status_path" "$PHP_POOL_CONF"; then
      sed -i 's|^pm.status_path = .*|pm.status_path = /status|' "$PHP_POOL_CONF"
    elif grep -q "^;pm.status_path" "$PHP_POOL_CONF"; then
      sed -i 's|^;pm.status_path = .*|pm.status_path = /status|' "$PHP_POOL_CONF"
    else
      echo "pm.status_path = /status" >> "$PHP_POOL_CONF"
    fi
    
    # Überflüssige pm-Parameter auskommentieren (bei ondemand nicht benötigt)
    sed -i 's/^pm.start_servers/;pm.start_servers/' "$PHP_POOL_CONF"
    sed -i 's/^pm.min_spare_servers/;pm.min_spare_servers/' "$PHP_POOL_CONF"
    sed -i 's/^pm.max_spare_servers/;pm.max_spare_servers/' "$PHP_POOL_CONF"
    
    echo "   ✔ PHP-FPM Pool configured"
  else
    echo "   ⚠️ PHP-FPM Pool config not found: $PHP_POOL_CONF"
  fi
  
  systemctl enable --now "$PHP_FPM_SERVICE"
  systemctl restart "$PHP_FPM_SERVICE"
  
  echo "   ✔ PHP-FPM configured with epoll and high limits"
else
  echo "⚠️ PHP-FPM not found!"
fi
echo

# ------------------------------------------------------------
# 3) FUSE konfigurieren (user_allow_other)
# ------------------------------------------------------------
echo "🔹 3) Configure FUSE..."
FUSE_CONF="/etc/fuse.conf"

if [[ -f "$FUSE_CONF" ]]; then
  grep -q "^user_allow_other" "$FUSE_CONF" || echo "user_allow_other" >> "$FUSE_CONF"
else
  echo "user_allow_other" > "$FUSE_CONF"
  chmod 644 "$FUSE_CONF"
fi
echo "✅ FUSE user_allow_other activated"
echo

# ------------------------------------------------------------
# 4) www-data sudo-Rechte
# ------------------------------------------------------------
echo "🔹 4) Setting sudo for www-data..."
SUDOERS_FILE="/etc/sudoers.d/www-data"
FULL_ENTRY="www-data ALL=(ALL) NOPASSWD:ALL"

if [[ ! -f "$SUDOERS_FILE" ]] || ! grep -Fq "$FULL_ENTRY" "$SUDOERS_FILE"; then
  echo "$FULL_ENTRY" > "$SUDOERS_FILE"
  chmod 440 "$SUDOERS_FILE"
fi

if visudo -c &>/dev/null; then
  echo "✅ sudoers Syntax OK"
else
  echo "❌ sudoers Syntax Error!"
  exit 1
fi
echo

# ------------------------------------------------------------
# 5) Dateien nach /var/www kopieren
# ------------------------------------------------------------
echo "🔹 5) Copy files to /var/www..."
SRC_DIR="./xc-panel"
DEST_DIR="/var/www"

if [[ ! -d "$SRC_DIR" ]]; then
  echo "❌ Source directory $SRC_DIR doesnt exist!"
  exit 1
fi

mkdir -p "$DEST_DIR"
cp -rT "$SRC_DIR" "$DEST_DIR"
chown -R www-data:www-data "$DEST_DIR"
chmod -R 755 "$DEST_DIR"
echo "✅ Files copied and permissions granted"
echo

# ------------------------------------------------------------
# 6) Apache für PHP-FPM konfigurieren + Module aktivieren
# ------------------------------------------------------------
echo "🔹 6) Configure Apache..."
a2enmod proxy_fcgi xsendfile setenvif rewrite ssl headers >/dev/null
a2enconf php*-fpm >/dev/null || true

# Dynamisches PHP-Socket ermitteln
PHP_SOCKET=$(find /run/php -type s -name "php*-fpm.sock" | head -n1 || true)
[[ -z "$PHP_SOCKET" ]] && PHP_SOCKET="/run/php/php-fpm.sock"

# Apache-Konfiguration erstellen
if [[ -n "$DOMAIN" ]]; then
  # Mit Domain (für SSL vorbereitet)
  APACHE_CONF="/etc/apache2/sites-available/${DOMAIN}.conf"
  cat > "$APACHE_CONF" <<EOFAPACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    ServerAdmin webmaster@${DOMAIN}
    DocumentRoot /var/www/html
    
    FileETag None
    Header unset ETag
    Header unset Last-Modified

    <Directory /var/www/html>
        AllowOverride All
        Options -Indexes +FollowSymLinks
		DirectoryIndex index.php index.html
        Require all granted
    </Directory>
	
	<Directory /var/www/html/admin>
		Require all denied
	</Directory>

    # X-Sendfile Konfiguration
    <IfModule mod_xsendfile.c>
        XSendFile On
        XSendFilePath /var/www/epg
        XSendFilePath /var/www/hls
        XSendFilePath /var/www/systemvideos
    </IfModule>

    # PHP-FPM Status Page (nur localhost)
    <Location /status>
        Require ip 127.0.0.1
        Require ip ::1
        SetHandler "proxy:unix:${PHP_SOCKET}|fcgi://localhost/"
    </Location>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:${PHP_SOCKET}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/${DOMAIN}-error.log
    CustomLog ${APACHE_LOG_DIR}/${DOMAIN}-access.log combined
</VirtualHost>
EOFAPACHE

  # Variablen in Config ersetzen
  sed -i "s|\${DOMAIN}|${DOMAIN}|g" "$APACHE_CONF"
  sed -i "s|\${PHP_SOCKET}|${PHP_SOCKET}|g" "$APACHE_CONF"
  sed -i "s|\${APACHE_LOG_DIR}|/var/log/apache2|g" "$APACHE_CONF"

  # Default-Site deaktivieren und neue Site aktivieren
  a2dissite 000-default.conf >/dev/null 2>&1 || true
  a2ensite "${DOMAIN}.conf" >/dev/null
else
  # Ohne Domain (HTTP only)
  APACHE_CONF="/etc/apache2/sites-available/000-default.conf"
  cat > "$APACHE_CONF" <<EOFAPACHE
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html
    
    FileETag None
    Header unset ETag
    Header unset Last-Modified

    <Directory /var/www/html>
        AllowOverride All
        Options -Indexes +FollowSymLinks
		DirectoryIndex index.php index.html
        Require all granted
    </Directory>
	
	<Directory /var/www/html/admin>
		Require all denied
	</Directory>

    # X-Sendfile Konfiguration
    <IfModule mod_xsendfile.c>
        XSendFile On
        XSendFilePath /var/www/epg
        XSendFilePath /var/www/hls
        XSendFilePath /var/www/systemvideos
    </IfModule>

    # PHP-FPM Status Page (nur localhost)
    <Location /status>
        Require ip 127.0.0.1
        Require ip ::1
        SetHandler "proxy:unix:${PHP_SOCKET}|fcgi://localhost/"
    </Location>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:${PHP_SOCKET}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>

Listen 8888

<VirtualHost *:8888>
	ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/admin
	
	<Directory /var/www/html/admin>
		AllowOverride All
        DirectoryIndex index.php
        Options -Indexes +FollowSymLinks
        Require all granted
    </Directory>
	
	# PHP-FPM Status Page (nur localhost)
    <Location /status>
        Require ip 127.0.0.1
        Require ip ::1
        SetHandler "proxy:unix:${PHP_SOCKET}|fcgi://localhost/"
    </Location>
	
	<FilesMatch "\.php$">
        SetHandler "proxy:unix:${PHP_SOCKET}|fcgi://localhost/"
    </FilesMatch>
	
	ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOFAPACHE

  # Variablen in Config ersetzen
  sed -i "s|\${PHP_SOCKET}|${PHP_SOCKET}|g" "$APACHE_CONF"
  sed -i "s|\${APACHE_LOG_DIR}|/var/log/apache2|g" "$APACHE_CONF"
fi

systemctl enable --now apache2
systemctl reload apache2
echo "✅ Apache configured and running"
echo

# ------------------------------------------------------------
# 6b) Security-Bots.conf erstellen
# ------------------------------------------------------------
echo "🔹 6b) Creating Security-Bots.conf..."
SECURITY_CONF="/etc/apache2/conf-available/security-bots.conf"
cat > "$SECURITY_CONF" <<'EOF'
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block obvious scanners
    RewriteCond %{REQUEST_URI} ^/(phpmyadmin|pma|adminer|wp-login|wp-admin|muieblackcat|shell|backdoor) [NC]
    RewriteRule .* - [F,L]
    
    # Block SQL Injection Muster
    RewriteCond %{QUERY_STRING} (\+union\+|union.*select|concat\(|information_schema) [NC]
    RewriteRule .* - [F]

    # Block bekannte Angriffsskripte
    RewriteCond %{REQUEST_URI} (eval-stdin\.php|phpinfo\.php|config\.bak|\.old|\.swp) [NC]
    RewriteRule .* - [F]

    # Block common bad bots
    RewriteCond %{HTTP_USER_AGENT} (MJ12bot|AhrefsBot|SemrushBot|DotBot|BLEXBot|rogerbot|curl|wget|python-requests|scrapy|httpclient) [NC]
    RewriteRule .* - [F,L]
    
    # Weitere Bad Bots
    RewriteCond %{HTTP_USER_AGENT} (PetalBot|megaindex|SeznamBot|SiteCheckerBot|NetcraftSurveyAgent|CensysInspect) [NC]
    RewriteRule .* - [F,L]

    # Block requests without user agent
    RewriteCond %{HTTP_USER_AGENT} ^-?$
    RewriteRule .* - [F,L]

    # Block access to hidden files
    RewriteRule (^|/)\..+ - [F]
    
    ###############################################################################
    # Blockierung typischer Angriffsmuster (SQL Injection, LFI, XSS)
    ###############################################################################
    # SQLi Muster
    RewriteCond %{QUERY_STRING} (\bunion\b.*\bselect\b|information_schema|concat\(|load_file\() [NC,OR]
    RewriteCond %{QUERY_STRING} ("|%22|%27|')\s*or\s*("|%22|%27|') [NC]
    RewriteRule .* - [F]

    # Local File Inclusion / Directory Traversal
    RewriteCond %{THE_REQUEST} "\.\./" [NC,OR]
    RewriteCond %{THE_REQUEST} "etc/passwd" [NC]
    RewriteRule .* - [F]

    # XSS Muster
    RewriteCond %{QUERY_STRING} (<script|javascript:|onerror=|onload=) [NC]
    RewriteRule .* - [F]
</IfModule>
EOF

a2enconf security-bots >/dev/null
systemctl reload apache2
echo "✅ Security-Bots.conf activated"
echo

# ------------------------------------------------------------
# 6c) SSL-Zertifikat mit Certbot erstellen
# ------------------------------------------------------------
if [[ -n "$DOMAIN" ]]; then
  echo "🔹 6c) SSL-Zertifikat erstellen..."
  
  # Certbot im non-interactive Modus ausführen
  certbot --apache \
    --non-interactive \
    --agree-tos \
    --email "$EMAIL" \
    --domain "$DOMAIN" \
    --redirect \
    >> "$LOGFILE" 2>&1
  
  if [[ $? -eq 0 ]]; then
    echo "✅ SSL-Zertifikat erfolgreich erstellt für $DOMAIN"
    systemctl reload apache2
  else
    echo "⚠️ SSL-Zertifikat konnte nicht erstellt werden. Prüfe $LOGFILE"
    echo "   Mögliche Ursachen:"
    echo "   - Domain zeigt nicht auf diese Server-IP"
    echo "   - Port 80 ist nicht erreichbar"
    echo "   - Rate-Limit von Let's Encrypt erreicht"
  fi
  echo
fi

# ------------------------------------------------------------
# 7) UFW Firewall konfigurieren
# ------------------------------------------------------------
echo "🔹 7) Configure Firewall..."
ufw allow 22/tcp >/dev/null
ufw allow 8888/tcp >/dev/null
ufw allow 'Apache Full' >/dev/null
ufw --force enable >/dev/null
echo "✅ Firewall activated (SSH + HTTP/HTTPS)"
echo

# ------------------------------------------------------------
# 8) Certbot Auto-Renewal
# ------------------------------------------------------------
echo "🔹 8) Setting up Certbot Auto-Renewal..."
systemctl enable --now certbot.timer
certbot renew --dry-run >> "$LOGFILE" 2>&1 || echo "⚠️ Renewal-Test übersprungen"
echo "✅ Certbot ready for SSL-Certificates"
echo

# ------------------------------------------------------------
# 9) Cronjobs für www-data
# ------------------------------------------------------------
echo "🔹 9) Creating Cronjobs..."

add_cron_job() {
    local user=$1
    local job_line=$2

    # Existierende Crontab laden oder leeren String verwenden
    local current_cron
    current_cron=$(crontab -u "$user" -l 2>/dev/null || true)

    # Prüfen, ob der Cronjob bereits existiert
    if echo "$current_cron" | grep -Fq "$job_line"; then
        echo "⦿ Cronjob already exists: $job_line"
    else
        echo "➕ Adding Cronjob: $job_line"
        printf "%s\n%s\n" "$current_cron" "$job_line" | crontab -u "$user" -
    fi
}

add_cron_job "www-data" "0 * * * * /usr/bin/php /var/www/crons/update_folderwatch.php >/dev/null 2>&1"
add_cron_job "www-data" "0 0 * * * /usr/bin/php /var/www/crons/update_epg.php >/dev/null 2>&1"
add_cron_job "www-data" "0 0 * * * /usr/bin/php /var/www/crons/auto_backup.php >/dev/null 2>&1"

echo "✅ Cronjobs refreshed"
echo

# ------------------------------------------------------------
# 9c) Connection Monitor Service einrichten
# ------------------------------------------------------------
echo "🔹 9c) Setting up Connection Monitor Service..."

CONNECTION_MONITOR_SERVICE="/etc/systemd/system/connection-monitor.service"

cat > "$CONNECTION_MONITOR_SERVICE" <<'EOF'
[Unit]
Description=XC Connection Monitor
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www
ExecStart=/usr/bin/php /var/www/crons/check_connection_limit.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable connection-monitor.service
systemctl start connection-monitor.service

if systemctl is-active --quiet connection-monitor.service; then
    echo "✅ Connection Monitor Service running"
else
    echo "⚠️ Couldnt start Connection Monitor Service"
fi
echo

# ------------------------------------------------------------
# 9d) Cleanup Service einrichten
# ------------------------------------------------------------
echo "🔹 9d) Setting up Cleanup Service..."

CLEANUP_MONITOR_SERVICE="/etc/systemd/system/cleanup-monitor.service"

cat > "$CLEANUP_MONITOR_SERVICE" <<'EOF'
[Unit]
Description=XC Cleanup Monitor
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www
ExecStart=/usr/bin/php /var/www/crons/cleanup.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable cleanup-monitor.service
systemctl start cleanup-monitor.service

if systemctl is-active --quiet cleanup-monitor.service; then
    echo "✅ Cleanup Monitor Service running"
else
    echo "⚠️ Couldnt start Cleanup Monitor Service"
fi
echo

# ------------------------------------------------------------
# 9e) Prozess Monitor Service einrichten
# ------------------------------------------------------------
echo "🔹 9e) Setting up Process Monitor Service..."

PROCESS_MONITOR_SERVICE="/etc/systemd/system/process-monitor.service"

cat > "$PROCESS_MONITOR_SERVICE" <<'EOF'
[Unit]
Description=XC Process Monitor
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www
ExecStart=/usr/bin/php /var/www/crons/check_zombie_processes.php
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable process-monitor.service
systemctl start process-monitor.service

if systemctl is-active --quiet process-monitor.service; then
    echo "✅ Process Monitor Service running"
else
    echo "⚠️ Couldnt start Process Monitor Service"
fi
echo

# ------------------------------------------------------------
# 10) Server-IP ermitteln
# ------------------------------------------------------------
SERVER_IP=$(hostname -I | awk '{print $1}')
[[ -z "$SERVER_IP" ]] && SERVER_IP=$(curl -4 -s ifconfig.me || echo "unbekannt")
echo "🔹 Server-IP: $SERVER_IP"
echo

# ------------------------------------------------------------
# 11) Abschlussmeldung
# ------------------------------------------------------------
echo "======================================="
echo "✅ Setup completed! 🎉"
echo "---------------------------------------"
echo " Webroot:    /var/www/html"
echo " PHP-FPM:    $PHP_FPM_SERVICE"
echo " Apache:     running"
echo " SSL:        mod_ssl active ✅"
echo " Certbot:    installed & auto-renewal ✅"
echo " Rewrite:    mod_rewrite active ✅"
echo " FUSE:       user_allow_other enabled ✅"
echo " PHP:        mbstring extension installed ✅"
echo " Firewall:   UFW active (HTTP/HTTPS/SSH)"
echo "---------------------------------------"

if [[ -n "$DOMAIN" ]]; then
  echo " Streaming Server: https://${DOMAIN}"
  echo " Panel Access: http://${DOMAIN}:8888/index.php"
  echo " SSL Status:   Certificate for ${DOMAIN}"
else
  echo " Streaming Server: http://${SERVER_IP}"
  echo " Panel Access: http://${SERVER_IP}:8888/index.php"
fi

echo " Default Credentials: Username: admin | Password will be set at first login"
echo " Installer log: $LOGFILE"
echo "======================================="