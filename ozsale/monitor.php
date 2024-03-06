<?php
date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

// Scraper monitor class
class OzsaleScraperMonitor
{
    var $max_threads = 10;
    var $queue = [];
    
    public function __construct()
    {
        $this->logg('---------------');
        $this->logg('Monitor started');
        $this->parse_catalog('https://www.ozsale.com.au/ApacHandlers.ashx/GetPublicSalesBanners?saleCategoryID=40f80218-a9e1-43c4-96ff-4c046d192a21&topSalesCount=3&useOzsaleSize=true&getPromotion=true&groupNo=&languageID=en&countryID=AS&userGroup=');
        $this->logg("Monitor stopped");
    }
    
    // Write log
    private function logg($text)
    {
        $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
        echo $log;
        file_put_contents($this->scraper_log, $log , FILE_APPEND | LOCK_EX);
    }

    // Run external scraper
    function run_scraper($saleIDs)
    {
        $this->logg("Run thread for saleIDs: $saleIDs");
        exec("nohup php ozsale.php $saleIDs > /dev/null 2>&1 &");
    }
    
    // Get page from website (5 attempts)
    private function get_page($url)
    {
        $html = -1;
        $i = 0;
        while ($html == -1)
        {
            $html = $this->curl($url);
            $i++;
            if ($i > 4) break;
        }
        if ($html == -1) 
        {
            $this->logg("Can't load page after 5 attempts: $url");
            return false;
        }
        else
            return $html;
    }
        
    // Get page from website
    // Return html
    private function curl($url)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $this->cookies_file,
            CURLOPT_COOKIEFILE => $this->cookies_file,
        );
        $this->logg("Get page $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
            //var_dump ($info);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
        return $html;
    }
    
    // Parse catalog html
    // Process all pages
    private function parse_catalog($url)
    {
        $this->logg('Get all sales from catalog');
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error());
            $this->logg($json);
            return;
        }
        // Get all sales
        $i = 0;
        foreach ($arr['d']['List'] as $list)
        {
            foreach ($list['Sales'] as $sale)
            {
                //$this->queue[$i] = $sale['ID'];
                $this->queue[$i % $this->max_threads][] = $sale['ID'];
                $i++;
            }
        }
        //var_dump($this->queue);
        if ($i == 0)
        {
            $this->logg('No sales found');
            return;
        }
        $this->logg($i.' sales found');
        for ($i = 0; $i < $this->max_threads; $i++)
        {
            if (!empty($this->queue[$i]))
            {
                $saleIDs = escapeshellarg(implode(',', $queue[$i]));
                $this->run_scraper($saleIDs);
            }
            break;  // debug
        }
    }

}

$monitor = new OzsaleScraperMonitor();
?>