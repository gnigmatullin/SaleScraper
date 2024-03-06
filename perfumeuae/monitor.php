<?php
date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

// Scraper monitor class
class PerfumeuaeScraperMonitor
{
    var $max_threads = 2;
    var $queue = [];
    var $dom; // DOM for parse HTML
    var $xpath;
    
    public function __construct()
    {
        $this->logg('---------------');
        $this->logg('Monitor started');
        $this->dom = new DOMDocument();
        $this->parse_catalog('https://perfumeuae.com/shop/');
        $this->logg("Monitor stopped");
    }
    
    // Write log
    private function logg($text)
    {
        $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
        echo $log;
        file_put_contents($this->scraper_log, $log , FILE_APPEND | LOCK_EX);
    }

    // Run regexp on a string and return the result
    private function regex( $regex, $input, $output = 0 )
    {
        $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
        if (!$match)
            $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
        return $match;
    }
    
    // Run external scraper
    function run_scraper($categories)
    {
        $this->logg("Run thread for categories: $categories");
        exec("nohup php perfumeuae.php $categories > /dev/null 2>&1 &");
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
    
    // Get DOM from HTML
    private function loadDOM($html)
    {
        $this->dom->loadHTML($html);
        $this->xpath = new DOMXPath($this->dom);
    }
    
    // Search for XPath and get value
    private function get_node_value($query)
    {
        $value = false;
        foreach ($this->xpath->query($query) as $node)
        {
            $value = trim($node->nodeValue);
            break;
        }
        return $value;
    }
    
    // Search for XPath and get attribute
    private function get_attribute($query, $attr)
    {
        $attribute = false;
        foreach ($this->xpath->query($query) as $node)
        {
            $attribute = $node->getAttribute($attr);
            break;
        }
        return $attribute;
    }
    
    // Parse catalog html
    // Process all pages
    private function parse_catalog($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse catalogue '.$url);
        $this->loadDOM($html);
        
        // Get all categories
        $this->logg('Get all categories');
        foreach ( $this->xpath->query('//ul[ @class = "product-categories" ]/li/a') as $category )
        {
            $url = $category->getAttribute('href');
            $url = $this->regex('#https://perfumeuae.com/product-category/(\S+)/#', $url, 1);
            $this->queue[$i % $this->max_threads][] = $url;
            $i++;
        }
        //var_dump($this->queue);
        if ($i == 0)
        {
            $this->logg('No categories found');
            return;
        }
        $this->logg($i.' categories found');
        for ($i = 0; $i < $this->max_threads; $i++)
        {
            if (!empty($this->queue[$i]))
            {
                $categories = escapeshellarg(implode(',', $this->queue[$i]));
                $this->run_scraper($categories);
            }
            //break;  // debug
        }
    }

}

$monitor = new PerfumeuaeScraperMonitor();
?>