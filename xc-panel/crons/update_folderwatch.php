<?php
	ini_set('memory_limit', '1024M'); // Mehr Memory
	set_time_limit(0); 				  // Kein Timeout
	
	$baseDir         = dirname(__DIR__);
	
	$binDir		     = "$baseDir/bin";
	$vodDir		     = "$baseDir/vod";
	$seriesDir		 = "$baseDir/series";
	$logDir		     = "$baseDir/log";
	$databaseDir     = "$baseDir/database";
	$watchlistFile   = "$databaseDir/watchlist.json";
	$moviesFile      = "$databaseDir/VOD Movies";
	$seriesFile      = "$databaseDir/VOD Series";
	$movieCountFile  = "$databaseDir/movieCount.txt";
	$seriesCountFile = "$databaseDir/seriesCount.txt";
	$settingsFile    = "$databaseDir/settings.json";
	$lockFile        = "$databaseDir/folderwatch.lock";
	
	$settings = json_decode(file_get_contents($settingsFile) ?: '{}', true) ?: [];

	// Prozessname setzen für bessere Erkennbarkeit
	cli_set_process_title("XC Folder Watch Update");
	
	$lastUpdate = $settings["lastVodUpdate"] ?? 0;
	if ($lastUpdate > time() - 3600)
	{
		//exit;
	}
	
	// Prüfen ob Script bereits läuft
	$lockHandle = fopen($lockFile, 'c');
	if (!flock($lockHandle, LOCK_EX | LOCK_NB))
	{
		logMessage("Script already running. Exiting.", "folderwatch");
		fclose($lockHandle);
		exit(0);
	}
	
	// Dateien initialisieren, falls nicht vorhanden
	if (!file_exists($moviesFile)) {
		file_put_contents($moviesFile, json_encode([], JSON_PRETTY_PRINT));
		chmod($moviesFile, 0777);
	}
	if (!file_exists($seriesFile)) {
		file_put_contents($seriesFile, json_encode([], JSON_PRETTY_PRINT));
		chmod($seriesFile, 0777);
	}
	if (!file_exists($watchlistFile)) {
		file_put_contents($watchlistFile, json_encode([], JSON_PRETTY_PRINT));
		chmod($watchlistFile, 0777);
	}
	if (!file_exists($movieCountFile)) {
		file_put_contents($movieCountFile, '0');
		chmod($movieCountFile, 0777);
	}
	if (!file_exists($seriesCountFile)) {
		file_put_contents($seriesCountFile, '0');
		chmod($seriesCountFile, 0777);
	}
	
	// Alle JSON-Dateien auf einmal laden
	$movies   = json_decode(file_get_contents($moviesFile), true) ?: [];
	$series   = json_decode(file_get_contents($seriesFile), true) ?: [];
	$folders  = json_decode(file_get_contents($watchlistFile), true) ?: [];
	
	
	$newMovies = [];
	$addedEpisodes = 0;
	$seriesUpdated = false; // Flag für TMDB-Updates an bestehenden Serien
	$moviesUpdated = false; // Flag für Prefix-Updates an bestehenden Filmen

	// Größten Index bestimmen - optimiert
	$maxMovieIndex = (int)(file_exists($movieCountFile) ? file_get_contents($movieCountFile) : 0);
	if (!empty($movies))
	{
		$videoIds = array_column($movies, 'VideoID');
		$maxFromMovies = !empty($videoIds) ? max(array_map('intval', $videoIds)) : 0;
		$maxMovieIndex = max($maxMovieIndex, $maxFromMovies);
	}
	
	$maxSeriesIndex = (int)(file_exists($seriesCountFile) ? file_get_contents($seriesCountFile) : 0);
	// Für Series: höchste VideoID aus allen Seasons/Episodes finden
	if (!empty($series))
	{
		foreach ($series as $show)
		{
			if (isset($show['Seasons']) && is_array($show['Seasons']))
			{
				foreach ($show['Seasons'] as $season)
				{
					if (isset($season['Episodes']) && is_array($season['Episodes']))
					{
						foreach ($season['Episodes'] as $episode)
						{
							$videoId = (int)($episode['VideoID'] ?? 0);
							$maxSeriesIndex = max($maxSeriesIndex, $videoId);
						}
					}
				}
			}
		}
	}
	
	$movieTemplate = [
		"Name" 		  	 => "",
		"Group" 	  	 => "FILMS",
		"CategoryPrefix" => "",
		"LogoUrl" 	  	 => "",
		"StreamUrl"   	 => "",
		"Filename"	  	 => "",
		"Timestamp"   	 => 0,
		"Year" 		  	 => "",
		"Rating"	  	 => "",
		"Rating5"	  	 => 0,
		"TmdbID" 	  	 => "",
		"VideoID" 	  	 => "",
		"Description" 	 => "",
		"SourceFile"  	 => ""
	];
					  
	$ffprobe = "$binDir/ffprobe";
	$autoEncode = !empty($settings["autoEncodeVod"]);
	$tmdbApiKey = $settings["tmdbApiKey"] ?? '';
	$tmdbLanguage 	 = $settings["tmdbApiLanguage"] ?? "en-EN";
	$oldTmdbLanguage = $tmdbLanguage; // Aktuelle TMDB-Sprache zwischenspeichern

	$useTmdb = !empty($tmdbApiKey) && !empty($settings["tmdbAutoScan"]);
	
	// Queue für parallele ffprobe-Jobs
	$encodingQueue = [];
	$maxParallelJobs = 2;
	
	// TMDB-Funktion für Filme
	$fetchTmdbMovie = function($movieName, $year = '') use ($tmdbApiKey, &$tmdbLanguage) {
		$query = urlencode($movieName);
		$yearParam = $year ? "&year=$year" : '';
		$url = "https://api.themoviedb.org/3/search/movie?api_key=$tmdbApiKey&query=$query&language=$tmdbLanguage$yearParam";
		
		$response = @file_get_contents($url);
		if (!$response) return null;
		
		$data = json_decode($response, true);
		if (empty($data['results'][0])) return null;
		
		$movie = $data['results'][0];
		
		// Details abrufen für mehr Infos (inkl. Genres)
		$detailUrl = "https://api.themoviedb.org/3/movie/{$movie['id']}?api_key=$tmdbApiKey&language=$tmdbLanguage";
		$detailResponse = @file_get_contents($detailUrl);
		if ($detailResponse) {
			$details = json_decode($detailResponse, true);
			$movie = array_merge($movie, $details);
		}
		
		// Genre ermitteln (erstes Genre aus der Liste)
		$genre = 'FILMS'; // Fallback
		if (!empty($movie['genres']) && is_array($movie['genres']) && !empty($movie['genres'][0]['name'])) {
			$genre = mb_strtoupper($movie['genres'][0]['name']);
		}	

		$rating = isset($movie['vote_average']) ? (float)$movie['vote_average'] : 0;
		// von 0–10 auf 0–5 umrechnen
		$rating5 = $rating / 2;
		// auf maximal 5 begrenzen
		$rating5 = min(5, $rating5);
		// auf ganze Zahl runden
		$rating5 = (int)round($rating5);
		
		return [
			'Name' => $movie['title'] ?? $movieName,
			'Year' => !empty($movie['release_date']) ? substr($movie['release_date'], 0, 4) : $year,
			'Rating' => "$rating",
			"Rating5" => $rating5,
			'TmdbID' => (string)$movie['id'],
			'Description' => $movie['overview'] ?? '',
			'LogoUrl' => !empty($movie['poster_path']) ? "https://image.tmdb.org/t/p/w500" . $movie['poster_path'] : '',
			'Group' => "$genre"
		];
	};
	
	// TMDB-Funktion für Serien
	$fetchTmdbSeries = function($seriesName) use ($tmdbApiKey, &$tmdbLanguage) {
		$query = urlencode($seriesName);
		$url = "https://api.themoviedb.org/3/search/tv?api_key=$tmdbApiKey&query=$query&language=$tmdbLanguage";
		
		$response = @file_get_contents($url);
		if (!$response) return null;
		
		$data = json_decode($response, true);
		if (empty($data['results'][0])) return null;
		
		$series = $data['results'][0];
		
		// Details abrufen
		$detailUrl = "https://api.themoviedb.org/3/tv/{$series['id']}?api_key=$tmdbApiKey&language=$tmdbLanguage";
		$detailResponse = @file_get_contents($detailUrl);
		if ($detailResponse) {
			$details = json_decode($detailResponse, true);
			$series = array_merge($series, $details);
		}
		
		return [
			'Name' => $series['name'] ?? $seriesName,
			'TmdbID' => (string)$series['id'],
			'LogoUrl' => !empty($series['poster_path']) ? "https://image.tmdb.org/t/p/w500" . $series['poster_path'] : ''
		];
	};
	
	// Vorbereiten für schnellere Hash-Lookups
	$existingMovieHashes = array_flip(array_keys($movies));
	
	// Für Series: Index nach SeriesID, OriginalName und Name aufbauen
	$seriesIndex = [];
	$seriesByOriginalName = [];
	$seriesByTmdbId = [];
	
	foreach ($series as $idx => $show)
	{
		$seriesId = $show['SeriesID'] ?? '';
		$name = $show['Name'] ?? '';
		$originalName = $show['OriginalName'] ?? '';
		$tmdbId = $show['TmdbID'] ?? '';
		
		// Index nach SeriesID (falls vorhanden)
		if ($seriesId)
		{
			$seriesIndex[mb_strtolower($seriesId)] = $idx;
		}
		
		// Index nach OriginalName (höchste Priorität für Matching)
		if ($originalName)
		{
			$seriesByOriginalName[normalize_series_name($originalName)] = $idx;
		}
		
		// Index nach aktuellem Name (niedrigere Priorität)
		if ($name)
		{
			$seriesIndex[normalize_series_name($name)] = $idx;
		}
		
		// Index nach TMDB-ID (für Duplikatserkennung)
		if ($tmdbId)
		{
			$seriesByTmdbId[$tmdbId] = $idx;
		}
	}
	
	function normalize_series_name($name) 
	{
		$name = mb_strtolower($name);
		// Sonderzeichen entfernen
		$name = preg_replace('/[._\-]+/', ' ', $name);
		// „&" und „und" vereinheitlichen
		$name = str_replace('und', '&', $name);
		// Mehrfache Leerzeichen auf eins reduzieren
		$name = preg_replace('/\s+/', ' ', $name);
		// Whitespace trimmen
		$name = trim($name);
		return $name;
	}

	foreach ($folders as $folderItem)
	{
		// Unterstützung für altes Format (nur String) und neues Format (Objekt)
		if (is_string($folderItem))
		{
			$folder = $folderItem;
			$type 	= 'movie'; // Standardwert für alte Einträge
			$prefix = '';
		}
		else
		{
			$folder = $folderItem['folder'] ?? '';
			$type 	= $folderItem['type'] ?? 'movie';
			$prefix = $folderItem['prefix'] ?? '';
			$overwriteTmdbLanguage = $folderItem['overwriteTmdbLanguage'] ?? '';
		}

		if (!empty($overwriteTmdbLanguage)) {
			$tmdbLanguage = $overwriteTmdbLanguage;
			serverLog("Overwriting TMDB language for folder '$folder' to '$tmdbLanguage'");
		} else {
			$tmdbLanguage = $oldTmdbLanguage; // Standard-Sprache aus den Einstellungen
		}
		
		if (empty($folder))
			continue;
		
		$path = "$baseDir/$folder/";
		
		if (!is_dir($path))
			continue;
		
		// Rekursive Funktion zum Durchsuchen von Ordnern
		$scanDirectory = function($dir) use (&$scanDirectory, $type, $prefix, $baseDir, $ffprobe, $autoEncode, $vodDir, $seriesDir,
												&$maxMovieIndex, &$maxSeriesIndex, &$newMovies, &$addedEpisodes, 
												&$existingMovieHashes, &$series, &$movies, &$seriesIndex, &$seriesByOriginalName, 
												&$seriesByTmdbId, &$seriesUpdated, &$moviesUpdated, $movieTemplate, &$encodingQueue,
												$useTmdb, $fetchTmdbMovie, $fetchTmdbSeries) {
			$files = scandir($dir);
			
			foreach ($files as $file)
			{
				if ($file === '.' || $file === '..')
					continue;
				
				$filePath = "$dir" . "/$file";
				
				// Wenn es ein Unterordner ist, rekursiv durchsuchen
				if (is_dir($filePath))
				{
					$scanDirectory($filePath);
					continue;
				}
				
				// Nur Dateien verarbeiten
				if (!is_file($filePath))
					continue;
				
				
				$hash = md5($file);
				$fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
				$pathInfo = pathinfo($file, PATHINFO_FILENAME);
				$itemName = $pathInfo;
				
				$pattern = '/^(.*?)(?:\.(\d{4}))?[-_.\s]*(?:[Ss](\d{1,2})[Ee](\d{1,2})|(\d{1,2})x(\d{1,2})|[Ee](\d{1,2}))(?=[-_.\s]|$)/i';
				
				// Je nach Typ verarbeiten
				if ($type === 'series')
				{
					// Format: SeriesName.S01E05.2023.mkv oder SeriesName.S01E05.mkv
					if (preg_match($pattern, $itemName, $m)) {
						$seriesName = trim(preg_replace('/[._]+/', ' ', $m[1])); // Punkte -> Leerzeichen
						$year       = $m[2] ?? '';

						if (!empty($m[3]) && !empty($m[4])) {
							// SxxExx
							$seasonNum  = (int)$m[3];
							$episodeNum = (int)$m[4];
						} elseif (!empty($m[5]) && !empty($m[6])) {
							// 1x02
							$seasonNum  = (int)$m[5];
							$episodeNum = (int)$m[6];
						} elseif (!empty($m[7])) {
							// nur E02  -> Season-Default 1
							$seasonNum  = 1;
							$episodeNum = (int)$m[7];
						}
						
						// Serie im Index suchen - PRIORITÄT: 1. OriginalName, 2. Name, 3. SeriesID
						$seriesSearchKey = normalize_series_name($seriesName);
						$seriesIdx = $seriesByOriginalName[$seriesSearchKey] ?? $seriesIndex[$seriesSearchKey] ?? null;
						
						// Wenn Serie nicht existiert, erstellen
						if ($seriesIdx === null)
						{
							serverLog("Adding new series: $seriesName");
							
							// Eindeutige SeriesID generieren
							$seriesID = md5($seriesName . time() . rand());
							
							$newSeries = [
								'SeriesID' => $seriesID,
								'Name' => $seriesName,
								'OriginalName' => $seriesName, // Original-Dateiname speichern
								'TmdbID' => '',
								'Group' => 'SERIES',
								'CategoryPrefix' => $prefix, // Prefix hinzufügen
								'LogoUrl' => '',
								'Timestamp' => time(),
								'Seasons' => []
							];
							
							// TMDB-Daten abrufen für neue Serie
							if ($useTmdb) {
								$tmdbData = $fetchTmdbSeries($seriesName);
								if ($tmdbData) {
									$tmdbId = $tmdbData['TmdbID'];

									// Prüfen, ob Serie mit gleicher TMDB-ID bereits existiert
									$existingByTmdb = $seriesByTmdbId[$tmdbId] ?? null;

									if ($existingByTmdb !== null) {
										// Serie existiert schon → Index auf vorhandene Serie setzen
										$seriesIdx = $existingByTmdb;
										$seriesByOriginalName[$seriesSearchKey] = $seriesIdx;
										
										// OriginalName zur bestehenden Serie hinzufügen, falls nicht vorhanden
										if (empty($series[$seriesIdx]['OriginalName'])) {
											$series[$seriesIdx]['OriginalName'] = $seriesName;
										}

										// Prefix aktualisieren falls vorhanden
										if (!empty($prefix) && empty($series[$seriesIdx]['CategoryPrefix'])) {
											$series[$seriesIdx]['CategoryPrefix'] = $prefix;
											$seriesUpdated = true;
										}
									} 
									else 
									{
										// Neue Serie anlegen
										$newSeries['TmdbID'] = $tmdbId;
										$newSeries['LogoUrl'] = $tmdbData['LogoUrl'];
									}
								}
							}
							
							// Nur neue Serie hinzufügen, wenn keine passende gefunden wurde
							if ($seriesIdx === null) {
								$series[] = $newSeries;
								$seriesIdx = count($series) - 1;
								// Indices aktualisieren
								$seriesByOriginalName[$seriesSearchKey] = $seriesIdx;
								$seriesIndex[mb_strtolower($seriesID)] = $seriesIdx;
								if (!empty($newSeries['TmdbID'])) {
									$seriesByTmdbId[$newSeries['TmdbID']] = $seriesIdx;
								}
							}
						}
						// TMDB-Daten nachträglich holen, falls noch nicht vorhanden
						elseif ($useTmdb && empty($series[$seriesIdx]['TmdbID'])) 
						{
							// Für TMDB-Suche den AKTUELLEN Namen der Serie verwenden
							$searchName = $series[$seriesIdx]['Name'] ?? $seriesName;

							serverLog("Updating series with TMDB data: $searchName");

							$tmdbData = $fetchTmdbSeries($searchName);
							if ($tmdbData) {
								// Name NICHT überschreiben, nur TMDB-ID und Logo
								$series[$seriesIdx]['TmdbID'] = $tmdbData['TmdbID'];
								$series[$seriesIdx]['LogoUrl'] = $tmdbData['LogoUrl'];
								
								// OriginalName setzen, falls nicht vorhanden
								if (empty($series[$seriesIdx]['OriginalName'])) {
									$series[$seriesIdx]['OriginalName'] = $seriesName;
								}

								// Prefix hinzufügen falls nicht vorhanden
								if (!empty($prefix) && empty($series[$seriesIdx]['CategoryPrefix'])) {
									$series[$seriesIdx]['CategoryPrefix'] = $prefix;
								}
								
								// TMDB-Index aktualisieren
								$seriesByTmdbId[$tmdbData['TmdbID']] = $seriesIdx;
								
								// Flag setzen, dass Serien-Datei gespeichert werden muss
								$seriesUpdated = true;
								
								serverLog("TMDB data found: ID {$tmdbData['TmdbID']}");
							}
						}
						// Prefix nachträglich hinzufügen, falls noch nicht vorhanden
						elseif (!empty($prefix) && empty($series[$seriesIdx]['CategoryPrefix']))
						{
							$series[$seriesIdx]['CategoryPrefix'] = $prefix;
							$seriesUpdated = true;
						}
						elseif (!empty($prefix) && $series[$seriesIdx]['CategoryPrefix'] !== $prefix)
						{
							$series[$seriesIdx]['CategoryPrefix'] = $prefix;
							$seriesUpdated = true;
						}
						
						// Episode-Objekt prüfen ob bereits vorhanden
						$seasonKey = (string)(int)$seasonNum;
						$existingSeason = $series[$seriesIdx]['Seasons'][$seasonKey] ?? null;
						
						if ($existingSeason && isset($existingSeason['Episodes']))
						{
							// Prüfen ob Episode bereits existiert (anhand Episode-Nummer)
							$episodeExists = false;
							foreach ($existingSeason['Episodes'] as $ep)
							{
								if ($ep['Episode'] === $episodeNum)
								{
									$episodeExists = true;
									break;
								}
							}
							
							if ($episodeExists)
								continue;
						}
						else
						{
							// Season erstellen
							$series[$seriesIdx]['Seasons'][$seasonKey] = [
								'Season' => $seasonKey,
								'Episodes' => []
							];
						}
						
						$filePath = str_replace("//", "/", $filePath);
						
						// Neue Episode hinzufügen
						$maxSeriesIndex++;
						
						$episodeObj = [
							'Episode' => $episodeNum,
							'Filename' => "$maxSeriesIndex.$fileExtension",
							'VideoID' => (string)$maxSeriesIndex,
							'SourceFile' => $filePath,
							'Timestamp' => time(),
							'Year' => $year
						];
						
						$series[$seriesIdx]['Seasons'][$seasonKey]['Episodes'][] = $episodeObj;
						
						// Nach Episode-Nummer sortieren
						usort($series[$seriesIdx]['Seasons'][$seasonKey]['Episodes'], function($a, $b) {
							return (int)$a['Episode'] <=> (int)$b['Episode'];
						});
						$addedEpisodes++;
						
						$autoEncode = true;
						if ($autoEncode)
						{
							$linkFile = "$seriesDir/$maxSeriesIndex.$fileExtension";
							
							if (!file_exists($linkFile))
							{
								serverLog("Making Symlink: $filePath -> $linkFile");
								symlink($filePath, $linkFile);
								
								/* Serien müssen erstmal nicht encodiert werden !!!!!!!!!!!!!!!!!!!!!!
								// Zur Queue hinzufügen statt direkt auszuführen
								$encodingQueue[] = [
									'type' => 'series',
									'filePath' => $filePath,
									'outputDir' => $seriesDir,
									'videoId' => $maxSeriesIndex,
									'extension' => $fileExtension
								];
								*/
							}
						}
					}
				}
				else // Movies
				{
					// Schnellere Hash-Prüfung mit isset
					if (isset($existingMovieHashes[$hash]))
					{
						if (!isset($movies[$hash]['CategoryPrefix']) || empty($movies[$hash]['CategoryPrefix']))
						{
							// Prefix nachträglich hinzufügen
							if (!empty($prefix))
							{
								$movies[$hash]['CategoryPrefix'] = $prefix;
								$moviesUpdated = true;
							}
						}
						elseif ($movies[$hash]['CategoryPrefix'] !== $prefix)
						{
							// Prefix aktualisieren
							$movies[$hash]['CategoryPrefix'] = $prefix;
							$moviesUpdated = true;
						}
						continue;
					}
					
					$maxMovieIndex++;
					$item = $movieTemplate;
					
					// Jahr extrahieren (z.B. Film.2023)
					if (preg_match('/^(.+)\.(\d{4})$/', $itemName, $matches))
					{
						$itemName = $matches[1];
						$itemYear = $matches[2];
					}
					else
					{
						$itemYear = '';
					}
					
					$filePath = str_replace("//", "/", $filePath);
					$itemName = str_replace(".", " ", $itemName);
					
					$item["Name"]	     	 = $itemName;
					$item["Year"]		 	 = $itemYear;
					$item["CategoryPrefix"]  = $prefix; // Prefix hinzufügen
					$item["Filename"]    	 = "$maxMovieIndex.$fileExtension";
					$item["VideoID"]     	 = (string)$maxMovieIndex;
					$item["Timestamp"]   	 = time();
					$item["SourceFile"]  	 = $filePath;
					$item["Group"]		 	 = trim($prefix . " " . "FILMS");
					
					// TMDB-Daten abrufen
					if ($useTmdb) 
					{
						$tmdbData = $fetchTmdbMovie($itemName, $itemYear);
						if ($tmdbData) {
							$item["Name"] = $tmdbData['Name'];
							$item["Year"] = $tmdbData['Year'];
							$item["TmdbID"] = $tmdbData['TmdbID'];
							$item["Rating"] = $tmdbData["Rating"];
							$item["Rating5"] = $tmdbData["Rating5"];
							$item["Description"] = $tmdbData['Description'];
							$item["LogoUrl"] = $tmdbData['LogoUrl'];
							$item["Group"] = trim($prefix . " " . $tmdbData['Group']);
						}
					}
					
					$newMovies[$hash] = $item;
					
					$autoEncode = true;
					if ($autoEncode)
					{
						$linkFile = "$vodDir/$maxMovieIndex.$fileExtension";
						
						if (!file_exists($linkFile))
						{
							symlink($filePath, $linkFile);
							
							// Zur Queue hinzufügen statt direkt auszuführen
							$encodingQueue[] = [
								'type' => 'movie',
								'filePath' => $filePath,
								'outputDir' => $vodDir,
								'videoId' => $maxMovieIndex,
								'extension' => $fileExtension
							];
						}
					}
				}
			}
		}; // Ende der rekursiven Funktion
		
		// Starte die rekursive Suche
		$scanDirectory($path);
	}
	
	// Paralleles Encoding mit maximal 4 gleichzeitigen Prozessen
	$autoEncode = true;
	if ($autoEncode && !empty($encodingQueue))
	{
		$runningProcesses = [];
		$queueIndex = 0;
		$totalJobs = count($encodingQueue);
		$startTime = time();
		$processTimeout = 60; // Timeout pro Prozess in Sekunden
		
		while ($queueIndex < $totalJobs || !empty($runningProcesses))
		{
			// Gestoppte Prozesse entfernen und Ergebnisse verarbeiten
			foreach ($runningProcesses as $key => $process)
			{
				$status = proc_get_status($process['handle']);
				$elapsed = time() - $process['startTime'];
				
				// Timeout-Check
				if ($elapsed > $processTimeout)
				{
					proc_terminate($process['handle']);
					serverLog("FFprobe timeout for video ID: " . $process['videoId']);
					
					fclose($process['pipes'][1]);
					fclose($process['pipes'][2]);
					proc_close($process['handle']);
					unset($runningProcesses[$key]);
					continue;
				}
				
				if (!$status['running'])
				{
					// Output in kleineren Chunks lesen um Memory zu schonen
					$output = '';
					while (!feof($process['pipes'][1])) {
						$chunk = fread($process['pipes'][1], 8192);
						if ($chunk === false) break;
						$output .= $chunk;
					}
					
					fclose($process['pipes'][1]);
					fclose($process['pipes'][2]);
					proc_close($process['handle']);
					
					// JSON-Datei schreiben
					if (!empty($output))
					{
						$outputArray = json_decode($output, true);
						
						if (json_last_error() === JSON_ERROR_NONE && isset($outputArray['streams'][0]))
						{
							$stream = $outputArray['streams'][0];
							$streamInfo = [
								"Resolution" => ($stream['width'] ?? '') . "x" . ($stream['height'] ?? ''),
								"Codec"      => $stream["codec_name"] ?? '',
								"Framerate"  => $stream["r_frame_rate"] ?? ''
							];
							
							$jsonFile = $process['outputDir'] . '/' . $process['videoId'] . '.json';
							file_put_contents($jsonFile, json_encode($streamInfo, JSON_PRETTY_PRINT));
						}
						else
						{
							serverLog("Invalid JSON for video ID: " . $process['videoId']);
						}
					}
					
					// Output explizit freigeben
					unset($output, $outputArray);
					unset($runningProcesses[$key]);
				}
			}
			
			// Neue Prozesse starten, wenn Platz ist
			while (count($runningProcesses) < $maxParallelJobs && $queueIndex < $totalJobs)
			{
				$job = $encodingQueue[$queueIndex];
				$queueIndex++;
				
				// Prüfen ob Datei existiert
				if (!file_exists($job['filePath']))
				{
					serverLog("File not found: " . $job['filePath']);
					continue;
				}
				
				$cmd = "$ffprobe -v error -show_format -show_streams -print_format json " . escapeshellarg($job['filePath']) . ' 2>&1';
				
				$descriptorspec = [
					0 => ["pipe", "r"],  // stdin
					1 => ["pipe", "w"],  // stdout
					2 => ["pipe", "w"]   // stderr
				];
				
				$process = proc_open($cmd, $descriptorspec, $pipes);
				
				if (is_resource($process))
				{
					// Non-blocking setzen
					stream_set_blocking($pipes[1], false);
					stream_set_blocking($pipes[2], false);
					
					$runningProcesses[] = [
						'handle' => $process,
						'pipes' => $pipes,
						'outputDir' => $job['outputDir'],
						'videoId' => $job['videoId'],
						'startTime' => time()
					];
				}
				else
				{
					serverLog("Failed to start process for: " . $job['filePath']);
				}
			}
			
			// Längere Pause zwischen Checks
			usleep(500000); // 500ms statt 100ms
			
			// Optional: Fortschritt ausgeben alle 10 Sekunden
			if ((time() - $startTime) % 10 == 0)
			{
				$processed = $queueIndex - count($runningProcesses);
				echo "Progress: $processed / $totalJobs processed\n";
			}
		}
		
		serverLog("All encoding jobs completed: $totalJobs total");
	}
	
	// Nur schreiben wenn es neue Einträge gibt oder Serien aktualisiert wurden
	$totalNew = count($newMovies) + $addedEpisodes;
	
	if ($totalNew > 0 || $seriesUpdated || $moviesUpdated)
	{
		if (count($newMovies) > 0 || $moviesUpdated)
		{
			$movies = array_merge($movies, $newMovies);
			file_put_contents($movieCountFile, $maxMovieIndex);
			file_put_contents($moviesFile, json_encode($movies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		
		if ($addedEpisodes > 0 || $seriesUpdated)
		{
			file_put_contents($seriesCountFile, $maxSeriesIndex);
			file_put_contents($seriesFile, json_encode($series, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		
		$logMsg = "Folder scan success.";
		if (count($newMovies) > 0) {
			$logMsg .= " Added " . count($newMovies) . " new movies.";
		}
		if ($addedEpisodes > 0) {
			$logMsg .= " Added " . $addedEpisodes . " new series episodes.";
		}
		if ($seriesUpdated && $addedEpisodes == 0) {
			$logMsg .= " Updated series metadata.";
		}
		if ($moviesUpdated) {
			$logMsg .= " Updated movie metadata.";
		}
		$logMsg .= "\n";
		
		serverLog("$logMsg");
	}
	else
	{
		//logMessage("Folder scan success. No new content found.", "folderwatch");
	}
	
	$settings["lastVodUpdate"] = time();
	file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
	
	// Lock freigeben
	flock($lockHandle, LOCK_UN);
	fclose($lockHandle);
	
	function logMessage(string $msg, string $file): void {
		$logFile = dirname(__DIR__) . "/log/{$file}.log";
		$time = date('[Y-m-d H:i:s]');
		@file_put_contents($logFile, "$time $msg\n", FILE_APPEND);
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