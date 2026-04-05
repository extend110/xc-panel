<?php
	/*
	// Limits user connections to allowed connections
	*/
	
	define("PROJECT_ROOT", dirname(__DIR__));
	
	// Prozessname setzen für bessere Erkennbarkeit
	cli_set_process_title("XC Connection Limit Monitor");
	
	// ============================================
	// WICHTIG: Endlosschleife für systemd service
	// ============================================
	while (true)
	{
		$monitorDir = PROJECT_ROOT . "/monitor";
		$usersFile  = PROJECT_ROOT . "/database/users.json";
		
		$userConnections   = [];
		$activeConnections = glob("$monitorDir/*.json");
		
		$users = json_decode(@file_get_contents($usersFile), true) ?? [];
		
		// Benutzer holen
		foreach ($activeConnections as $activeConnection)
		{
			// Rate-Limit-Dateien überspringen
			if (stripos(basename($activeConnection), "ratelimit_") !== false)
				continue;

			$currentData = json_decode(@file_get_contents($activeConnection), true);
			$currentUser = $currentData["user"];
			if (!array_key_exists($currentData["user"], $userConnections))
			{
				$userConnections[$currentUser] = [];
			}
		}
		
		// Aktive Verbindungen den Benutzern zuordnen
		foreach ($activeConnections as $activeConnection)
		{
			// Direct-Streams überspringen
			if (stripos(basename($activeConnection), "direct_") !== false)
				continue;

			// Rate-Limit-Dateien überspringen
			if (stripos(basename($activeConnection), "ratelimit_") !== false)
				continue;

			if (!is_file($activeConnection))
				continue;
			
			$connectionData = json_decode(@file_get_contents($activeConnection), true);
			if (!is_array($connectionData))
				continue;
			
			$currentUser = $connectionData["user"];
			
			$userConnections[$currentUser][] = [
				"file" => $activeConnection,
				"data" => $connectionData
			];
		}
		
		$killedConnections = 0;
		
		// Jeden Benutzer durchlaufen und Limit prüfen, evtl. ältesten Stream killen
		foreach ($userConnections as $user => $connections)
		{
			$activeConnections = count($connections);
			
			if ($activeConnections === 0)
				continue;
			
			// Nach Start-Zeit sortieren (älteste zuerst)
			usort($connections, function($a, $b) {
				return $a["data"]["start"] <=> $b["data"]["start"];
			});
			
			$maxConnections = $users[$user]["max_conns"];
			
			if ($activeConnections <= $maxConnections)
				continue;
			
			// Älteste Verbindung nehmen
			$oldestConnection = $connections[0];
			$file = $oldestConnection["file"];
			$data = $oldestConnection["data"];
			
			if (file_exists($file) && !isset($data["kill_connection"]))
			{
				$data["kill_connection"] = true;
				file_put_contents($file, json_encode($data)); 
				$killedConnections++;
			}
		}
		
		if ($killedConnections > 0)
			echo "Killed Connections: $killedConnections";
		
		sleep(10);
	}
?>