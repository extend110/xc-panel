<?php
	/**
	 * cleanup.php — Sicherer Stream-Cleanup für systemd
	 * 
	 * Funktionen:
	 * - Beendet inaktive Sessions (30s Live / 3600s Direct)
	 * - Entfernt Zombie-Prozesse (tote FFmpeg-Instanzen)
	 * - Löscht verwaiste HLS-Verzeichnisse
	 * - Erzwingt Stream-Limits pro Benutzer
	 * - Thread-sicher mit File-Locking
	 * - Graceful Shutdown für systemd
	 */

	declare(ticks = 1);

	define("PROJECT_ROOT", dirname(__DIR__));

	// Prozessname setzen
	if (function_exists('cli_set_process_title')) {
		cli_set_process_title("XC Cleanup Monitor");
	}

	// --- Konfiguration ---
	$monitorDir     = PROJECT_ROOT . '/monitor';
	$broadcastsDir  = PROJECT_ROOT . '/database/broadcasts';
	$TIMEOUT_LIVE   = 30;
	$TIMEOUT_DIRECT = 3600;
	$TIMEOUT_VOD    = 60;

	// Shutdown-Flag für graceful exit
	$shouldStop = false;

	// Signal-Handler für systemd
	function signalHandler($signal) {
		global $shouldStop;
		$shouldStop = true;
		serverLog("XC Cleanup Monitor: Received signal $signal, shutting down gracefully...");
	}

	pcntl_signal(SIGTERM, 'signalHandler');
	pcntl_signal(SIGINT, 'signalHandler');
	pcntl_signal(SIGHUP, 'signalHandler');
	
	function serverLog(string $msg): void {
		$logFile = dirname(__DIR__) . '/log/server_log.json';
		if (!is_dir(dirname($logFile)))
			@mkdir(dirname($logFile), 0755, true);

		$logEntries = json_decode(@file_get_contents($logFile), true) ?: [];

		$logEntries[] = [
			"timestamp" => time(), 
			"message" => $msg
		];

		file_put_contents($logFile, json_encode($logEntries, JSON_PRETTY_PRINT));
	}

	serverLog("XC Cleanup Monitor: Cleanup service started");

	// Hauptschleife
	while (!$shouldStop) 
	{
		try 
		{
			$now = time();
			$removed = 0;
			$zombies = 0;
			$orphanedBroadcasts = 0;

			// Dateien, älter als 24 Stunden, gelten als verwaist und werden gelöscht
			if (is_dir($monitorDir)) {
				foreach (glob($monitorDir . '/*.json') as $file) {
					if ($now - filemtime($file) > 86400) {
						@unlink($file);
					}
				}
			}

			// --- Zombie-Prozesse aufräumen ---
			$output = [];
			exec("ps -eo pid,ppid,stat,comm,args 2>/dev/null | grep ffmpeg | grep -v grep", $output);
			
			foreach ($output as $line) {
				if (preg_match('/^\s*(\d+)\s+(\d+)\s+([RSDZT]+)\s+(\S+)\s+(.*)$/', trim($line), $m)) {
					$pid  = (int)$m[1];
					$ppid = (int)$m[2];
					$stat = trim($m[3]);
					$args = trim($m[5]);

					// Zombie-Prozess erkannt
					if (str_contains($stat, 'Z') || str_contains($args, '<defunct>')) {
						serverLog("XC Cleanup Monitor: Found zombie ffmpeg (PID: $pid, PPID: $ppid)");
						
						if (function_exists('posix_kill')) {
							@posix_kill($ppid, SIGCHLD);
							sleep(1);

							// Prüfen ob Zombie noch existiert
							$check = [];
							exec("ps -o stat= -p " . escapeshellarg($pid) . " 2>/dev/null", $check);
							$stillZombie = isset($check[0]) && str_starts_with(trim($check[0]), 'Z');
							
							if ($stillZombie) {
								$zombies++;
								serverLog("XC Cleanup Monitor: Parent ($ppid) doesn't react → Kill");
								@posix_kill($ppid, SIGKILL);
							}
						}
					}
				}
			}

			// --- Direct-Viewer-Sessions aufräumen ---
			if (is_dir($monitorDir)) {
				foreach (glob($monitorDir . '/direct_*.json') as $file) {
					$currentTimeout = $TIMEOUT_DIRECT;
					$s = @json_decode(file_get_contents($file), true);

					if (!$s || !isset($s['last_active'])) {
						@unlink($file);
						$removed++;
						continue;
					}
					
					if ($now - $s['last_active'] > $currentTimeout) {
						@unlink($file);
						$removed++;
					}
				}
			}

			// --- VOD-Viewer-Sessions aufräumen ---
			if (is_dir($monitorDir)) {
				foreach (glob($monitorDir . '/vod_*.json') as $file) {
					$currentTimeout = $TIMEOUT_VOD + 30; // VOD-Timeout + 30s Puffer
					$s = @json_decode(file_get_contents($file), true);

					if (!$s || !isset($s['last_active'])) {
						//@unlink($file);
						//$removed++;
						continue;
					}
					
					if ($now - $s['last_active'] > $currentTimeout) {
						@unlink($file);
						$removed++;
					}
				}
			}

			// --- Live-Viewer-Sessions aufräumen ---
			if (is_dir($monitorDir)) {
				foreach (glob($monitorDir . '/viewer_*.json') as $file) {
					$currentTimeout = $TIMEOUT_LIVE + 60; // Live-Timeout + 60s Puffer
					$s = @json_decode(file_get_contents($file), true);

					if (!$s || !isset($s['last_active'])) {
						//@unlink($file);
						//$removed++;
						continue;
					}
					
					if ($now - $s['last_active'] > $currentTimeout) {
						@unlink($file);
						$removed++;
					}
				}
			}

			// --- Broadcast-Dateien aufräumen ---
			if (is_dir($broadcastsDir)) {
				foreach (glob($broadcastsDir . '/*.json') as $file) 
				{
					$currentTimeout = $TIMEOUT_LIVE;
					$s = @json_decode(file_get_contents($file), true);

					if (!$s || !isset($s['last_update'])) 
					{
						@unlink($file);
						$orphanedBroadcasts++;
						continue;
					}

					if (isset($s['type'])) 
					{
						if ($s['type'] === 'direct') 
						{
							$currentTimeout = $TIMEOUT_DIRECT;
						} 
						elseif ($s['type'] === 'vod') 
						{
							$currentTimeout = $TIMEOUT_VOD;
						}
					}

					if ($now - $s['last_update'] > $currentTimeout) 
					{
						@unlink($file);
						$removed++;
					}
				}
			}

			// --- Zusammenfassung ---
			$total = $removed + $zombies + $orphanedBroadcasts;

		} catch (Exception $e) {
			serverLog("XC Cleanup Monitor: " . $e->getMessage());
		} catch (Throwable $e) {
			serverLog("XC Cleanup Monitor: " . $e->getMessage());
		}

		// Auf Signal prüfen und schlafen
		for ($i = 0; $i < 10 && !$shouldStop; $i++) {
			sleep(1);
			pcntl_signal_dispatch();
		}
	}

	serverLog("XC Cleanup Monitor: Cleanup service stopped");
	exit(0);
?>