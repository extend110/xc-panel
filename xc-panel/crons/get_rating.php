<?php

    $baseDir         = dirname(__DIR__);

	$databaseDir     = "$baseDir/database";
	$settingsFile    = "$databaseDir/settings.json";
	
	$settings = json_decode(file_get_contents($settingsFile) ?: '{}', true) ?: [];

    $tmdbApiKey = $settings["tmdbApiKey"] ?? '';
	$tmdbLanguage = $settings["tmdbApiLanguage"] ?? "en-EN";

    $name = $argv[1];
    $year = $argv[2];

    print_r(GetRating($name, $year));

    function GetRating(string $movieName, string $movieYear) 
    {
        global $tmdbApiKey, $tmdbLanguage;

        $query = urlencode($movieName);
		$yearParam = $movieYear ? "&year=$movieYear" : '';
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
			'Year' => !empty($movie['release_date']) ? substr($movie['release_date'], 0, 4) : $movieYear,
			'Rating' => "$rating",
			"Rating5" => $rating5,
			'TmdbID' => (string)$movie['id'],
			'Description' => $movie['overview'] ?? '',
			'LogoUrl' => !empty($movie['poster_path']) ? "https://image.tmdb.org/t/p/w500" . $movie['poster_path'] : '',
			'Group' => "$genre"
		];
    }
?>