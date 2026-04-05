<?php
	require_once __DIR__ . '/vendor/autoload.php';
	use GeoIp2\Database\Reader;
	
	function GetIpISO($ip)
	{
		static $reader = null;
		
		try
		{
			if ($reader === null) {
				$reader = new Reader(__DIR__ . '/GeoLite2-Country.mmdb');
			}

			$record = $reader->country($ip);			
			$iso    = $record->country->isoCode;
			
			return strtolower($iso);
		}
		catch (\Exception $e)
		{
			return "Invalid Input!";
		}
	}
	
	function GetISP($ip)
	{
		static $reader = null;
		
		try 
		{
			if ($reader === null) {
				$reader = new Reader(__DIR__ . '/GeoIP2-ISP.mmdb');
			}

			$record = $reader->isp($ip); // ISP DB hat die isp()-Methode
			
			return $record->isp ?? 'ISP not found';
		} 
		catch (\Exception $e) 
		{
			return 'ISP not found';
			//echo "Lookup fehlgeschlagen: " . $e->getMessage();
		}
	}
	
	function GetIpData($ip)
	{
		static $reader = null;
		
		try
		{
			if ($reader === null) {
				$reader = new Reader(__DIR__ . '/GeoLite2-Country.mmdb');
			}

			$record = $reader->country($ip);
			
			return json_encode($record, JSON_PRETTY_PRINT);
		}
		catch (\Exception $e)
		{
			return "Invalid Input!";
		}
	}
?>