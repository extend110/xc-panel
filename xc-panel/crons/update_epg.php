<?php
    /* =======================
    KONFIGURATION
    ======================= */

    define('CHUNK_SIZE', 1048576);
    define('MAX_LOG_ENTRIES', 1000);
    define('FILE_PERMISSIONS', 0644);
    define('DIR_PERMISSIONS', 0755);

    ini_set('memory_limit', '1024M');
    ini_set('max_execution_time', 0);
    libxml_use_internal_errors(true);

    /* =======================
    VERZEICHNISSE
    ======================= */

    $baseDir     = dirname(__DIR__);
    $databaseDir = "$baseDir/database";
    $epgDir      = "$baseDir/epg";
    $logDir      = "$baseDir/log";

    $epgSourcesFile = "$databaseDir/epgSources.json";
    $logFile        = "$logDir/epg.json";
    $outputFile     = "$epgDir/merged.xml";

    // Prozessname setzen für bessere Erkennbarkeit
	cli_set_process_title("XC EPG Update");

    /* =======================
    VERZEICHNIS CHECK
    ======================= */

    foreach ([$databaseDir, $epgDir, $logDir] as $dir) {
        if (!is_dir($dir)) mkdir($dir, DIR_PERMISSIONS, true);
        if (!is_writable($dir)) die("Nicht beschreibbar: $dir\n");
    }

    if (!file_exists($logFile)) {
        file_put_contents($logFile, json_encode([]));
    }

    /* =======================
    DATEN LADEN
    ======================= */

    $logEntries = json_decode(file_get_contents($logFile), true) ?? [];
    $epgSources = json_decode(file_get_contents($epgSourcesFile), true) ?? [];

    if (empty($epgSources)) {
        serverLog("No EPG-Sources available");
        exit(1);
    }

    /* =======================
    DOWNLOAD
    ======================= */
    $inputFiles = downloadEpgFiles($epgSources, $epgDir, $logEntries, $logFile);

    /* epgSources IMMER speichern */
    file_put_contents($epgSourcesFile, json_encode($epgSources, JSON_PRETTY_PRINT));
    chmod($epgSourcesFile, FILE_PERMISSIONS);

    if (empty($inputFiles)) {
        serverLog("No EPG-Files loaded");
        exit(1);
    }

    /* =======================
    MERGE
    ======================= */

    mergeEpgFiles($inputFiles, $outputFile, $logEntries, $logFile);

    /* =======================
    KOMPRESS
    ======================= */
    $gz = gzopen("$outputFile.gz", 'wb9');
    $in = fopen($outputFile, 'rb');

    while (!feof($in)) {
        gzwrite($gz, fread($in, CHUNK_SIZE));
    }

    fclose($in);
    gzclose($gz);
    chmod("$outputFile.gz", FILE_PERMISSIONS);

    /* =======================
    CLEANUP
    ======================= */

    foreach ($inputFiles as $f) {
        @unlink($f);
    }

    /* =======================
    FUNKTIONEN
    ======================= */

    function mergeEpgFiles(array $files, string $output, array &$logEntries, string $logFile)
    {
        $out = fopen($output, 'w');
        stream_set_write_buffer($out, 1024 * 1024);

        fwrite($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite($out, "<tv generator-info-name=\"UltraFast XMLTV Merge\">\n");

        $seenChannels = [];
        $channelCount = 0;
        $programmeCount = 0;

        foreach ($files as $file) {
            $r = new XMLReader();
            $r->open($file, null,
                LIBXML_NONET |
                LIBXML_COMPACT |
                LIBXML_PARSEHUGE
            );

            while ($r->read()) {
                if ($r->nodeType !== XMLReader::ELEMENT) continue;

                /* CHANNEL */
                if ($r->name === 'channel') {
                    $xml = $r->readOuterXML();
                    if (preg_match('/id="([^"]+)"/', $xml, $m)) {
                        if (!isset($seenChannels[$m[1]])) {
                            $seenChannels[$m[1]] = true;
                            fwrite($out, $xml . "\n");
                            $channelCount++;
                        }
                    }
                    continue;
                }

                /* PROGRAMME */
                if ($r->name === 'programme') {
                    fwrite($out, $r->readOuterXML() . "\n");
                    $programmeCount++;

                    continue;
                }
            }
            $r->close();
        }

        fwrite($out, "</tv>\n");
        fclose($out);
    }

    function downloadEpgFiles(array &$sources, string $dir, array &$logEntries, string $logFile): array
    {
        $files = [];
        $i = 1;

        foreach ($sources as &$src) {
            $target = "$dir/xml_$i";

            $data = @file_get_contents($src['source']);
            if ($data === false) {
                $src['last_update'] = null;
                $src['error'] = 'Download failed';
                serverLog("Download failed: {$src['source']}");
                continue;
            }

            file_put_contents($target, $data);
            chmod($target, FILE_PERMISSIONS);

            $src['last_update'] = time();
            $src['error'] = null;

            $files[] = $target;
            $i++;
        }
        unset($src);

        return $files;
    }

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
?>