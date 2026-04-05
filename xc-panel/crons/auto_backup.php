<?php
    define("PROJECT_ROOT", dirname(__DIR__));

    // Prozessname setzen
    if (function_exists('cli_set_process_title')) {
        cli_set_process_title("XC Auto Backup");
    }

    // --- Konfiguration ---
    $backupFolder   = PROJECT_ROOT . '/backups';
    $folderToBackup = PROJECT_ROOT . '/database';

    if (!is_dir($backupFolder)) {
        mkdir($backupFolder, 0755, true);
    }
    
    // Backup alle 24 Stunden per Cron erstellen, maximal 6 Backups behalten. Das Älteste Backup wird gelöscht, wenn die Anzahl der Backups 6 überschreitet.
    $backups = array_filter(scandir($backupFolder), function($item) use ($backupFolder) {
        return is_file($backupFolder . '/' . $item) && pathinfo($item, PATHINFO_EXTENSION) === 'zip';
    });
    usort($backups, function($a, $b) use ($backupFolder) {
        return filemtime($backupFolder . '/' . $b) - filemtime($backupFolder . '/' . $a);
    });
    if (count($backups) > 5) {
        @unlink($backupFolder . '/' . end($backups));
    }

    $zip = new ZipArchive();
    $backupFileName = 'backup_' . date('Ymd_His', time() + 3600) . '.zip';
    $backupFilePath = $backupFolder . '/' . $backupFileName;

    if ($zip->open($backupFilePath, ZipArchive::CREATE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderToBackup),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($folderToBackup) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
        serverLog("Backup created successfully: $backupFileName");
    }
    else 
    {
        serverLog("Failed to create backup: " . $zip->getStatusString());
        exit(1);
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