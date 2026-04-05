<?php
	/*
	// Checks for zombie worker processes and kills them every 5 minutes
	*/
	
	define("PROJECT_ROOT", dirname(__DIR__));
	
	// Prozessname setzen für bessere Erkennbarkeit
	cli_set_process_title("XC Process Monitor");

    // Broadcast-Worker prüfen
    $broadcastDir  = PROJECT_ROOT . "/database/broadcasts";

    while (true)
    {
        $workerNames = [];
        
        $cmd    = "ps aux | grep Worker | grep -v grep";
        $result = shell_exec($cmd);

        preg_match_all(
            '/^\S+\s+(\d+).*?Worker:\s*(.+)$/m',
            $result,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $workerNames[] = [
                'pid'    => (int)$m[1],
                'worker' => $m[2],
            ];
        }

        $timeNow         = time();
        $orphanedLimit   = 300; // 5 Minuten
        $orphanedWorkers = 0;

        // FFMPEG-Pids holen und Broadcast prüfen
        foreach ($workerNames as $key => $worker) 
        {
            $hash          = md5($worker['worker']);
            $broadcastFile = "$broadcastDir/$hash.json";

            $workerPid = $worker['pid'];
            $ffmpegPid = null;

            if (isPidRunning($workerPid + 1)) 
            {
                $ffmpegPid = $workerPid + 1;
            } 
            elseif (isPidRunning($workerPid - 1)) 
            {
                $ffmpegPid = $workerPid - 1;
            }

            $start = getProcessStartTime($workerPid);
            $runtime = $timeNow - $start->getTimestamp();


            $workerNames[$key]['runtime'] = gmdate('H:i:s', $runtime);
            $workerNames[$key]['ffmpeg_pid'] = $ffmpegPid;

            if (!file_exists($broadcastFile) && $runtime > $orphanedLimit) 
            {
                $workerNames[$key]['status'] = 'orphaned';
                $orphanedWorkers++;
            } 
            else 
            {
                $workerNames[$key]['status'] = 'active';
            }            
        }

        if ($orphanedWorkers > 0)
        {
            echo "Found $orphanedWorkers orphaned workers...\n";

            foreach ($workerNames as $key => $worker)
            {
                if ($worker['status'] === 'orphaned') 
                {
                    $pid       = $worker['pid'];
                    $ffmpegPid = $worker['ffmpeg_pid'];
                    echo "Killing orphaned worker PID $pid (Runtime: {$worker['runtime']})...\n";
                    shell_exec("kill -9 $pid");

                    if ($worker['ffmpeg_pid'] !== null) 
                    {
                        $ffmpegPid = $worker['ffmpeg_pid'];
                        echo "Killing associated FFmpeg PID $ffmpegPid...\n";
                        shell_exec("kill -9 $ffmpegPid");
                    }
                }
            }
        }

        // Alle 60 Sekunden prüfen
        sleep(60);
    }

    function isPidRunning(int $pid): bool
    {
        return is_dir("/proc/$pid");
    }

    function getProcessStartTime(int $pid): ?DateTime
    {
        $statFile   = "/proc/$pid/stat";
        $uptimeFile = "/proc/uptime";

        if (!is_readable($statFile) || !is_readable($uptimeFile)) {
            return null;
        }

        $stat = file_get_contents($statFile);
        $uptime = (float)explode(' ', file_get_contents($uptimeFile))[0];

        // Feld 22 = Startzeit in Jiffies
        $parts = explode(' ', $stat);
        $startTicks = (int)$parts[21];

        // System-Ticks pro Sekunde (meist 100)
        $ticksPerSecond = (int)shell_exec('getconf CLK_TCK') ?: 100;

        $processUptime = $startTicks / $ticksPerSecond;
        $startTimestamp = time() - ($uptime - $processUptime);

        return (new DateTime())->setTimestamp((int)$startTimestamp);
    }

?>