<?php
require_once 'db.php';

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

/**
 * @brief Class SaleScraper
 * @details Main scraper class
 */
class SaleScraper
{
    var $site_name = 'NoName';      ///< Site name
    var $site_id;                   ///< ID in sites table (DB)
    var $baseurl = '';              ///< Site URL in sites table
    var $currency_code = 'ZAR';     ///< Currency code
    var $currency_id;               ///< ID in curriencies table (DB)
    var $category;                  ///< Category name
    var $category_id;               ///< Category ID
    var $from_page;
    var $max_pages;
    var $scraper_log;               ///< Log file name
    var $cookies_file;              ///< Cookies file name
    var $db;                        ///< PDO
    var $nodb = false;              ///< true - run without DB
    var $dom;                       ///< DOM for parse HTML
    var $xpath;                     ///< XPath
    var $product_ids = [];          ///< IDs list to avoid duplicates
    var $items = [];                ///< Scrapped items list
    var $items_count = 0;           ///< Scrapped items count
    var $debug = 0;                 ///< Debug option, 1 - for debug mode (scrape one item, write results into debug_results table), 2 - for advanced debug mode (scrape all items, write results into debug_results table)
    var $curl_options = [];         ///< Active CURL options
    var $end_date;                  ///< Sale end date
    /// CURL allowed codes
    var $curl_allowed_codes = [200, 201, 202, 203, 204, 205, 206, 207, 208, 226, 300, 301, 302, 303, 304, 305, 306, 307, 308];
    var $curl_max_attempts = 5;     ///< Max attempts to get page with CURL
    var $curl_errors = 0;           ///< CURL errors counter
    var $curl_max_errors = 10;      ///< Max allowed CURL errors before scraper will stopped
    var $db_errors = 0;             ///< DB errors counter
    var $db_max_errors = 10;        ///< Max allowed DB errors before scraper will stopped
    var $db_max_items = 20;         ///< Max items count before db_max_errors will cleared
    /// Proxy server options
    var $use_proxy = false;
    var $proxy_addrport = 'us-wa.proxymesh.com:31280';
    var $proxy_userpwd  = 'runway:9!x&Rf-aFDV?bJ9#';

    /**
     * @brief SaleScraper class constructor
     * @param string $site_name       [Name of website for scrapping. Must be located in DB sites table]
     * @param string $currency_code   [Currency code for scraping. Must be located in DB currencies table]
     * @param string $arguments       [Command line arguments string]
     */
    public function __construct($site_name, $currency_code, $arguments = null)
    {
        // DB connection parameters (from db.php)
        global $db_servername;
        global $db_username;
        global $db_password;
        global $db_database;

        // Set site name and currency code
        $this->site_name = $site_name;
        $this->currency_code = $currency_code;

        // Parse command line arguments
        if (count($arguments) > 1)
        {
            foreach ($arguments as $arg)
            {
                // Category name (to scrape one category)
                if (strpos($arg, '--category=') !== false)
                {
                    $this->category = $this->regex('#--category=([\S]+)#', $arg, 1);
                    $this->logg('Category: '.$this->category);
                }
                // From page (to scrape from specified page)
                if (strpos($arg, '--from_page=') !== false)
                {
                    $this->from_page = $this->regex('#--from_page=([\S]+)#', $arg, 1);
                    $this->logg('From page: '.$this->from_page);
                }
                // Max pages amount (to scrape required pages amount)
                if (strpos($arg, '--max_pages=') !== false)
                {
                    $this->max_pages = $this->regex('#--max_pages=([\S]+)#', $arg, 1);
                    $this->logg('Max pages: '.$this->max_pages);
                }
                // Debug mode
                else if (strpos($arg, '--debug=') !== false)
                {
                    $this->debug = $this->regex('#--debug=([\d]+)#', $arg, 1);
                    $this->logg('Debug mode: '.$this->debug);
                }
                // No DB mode
                else if (strpos($arg, '--nodb=') !== false)
                {
                    $this->nodb = $this->regex('#--nodb=([\d]+)#', $arg, 1);
                    $this->logg('No DB mode: '.$this->nodb);
                }
            }
        }

        // Setup log file
        if (isset($this->category)){     // Log for one category scrape
            $this->scraper_log = './log/'.strtolower($this->site_name).'_'.str_replace('/', '_', $this->category).'.log';
        }
        else if (isset($this->from_page)) {
            $this->scraper_log = './log/'.strtolower($this->site_name).'_'.$this->from_page.'.log';
        }
        else {   // One common log for scraper
            //$this->scraper_log = './log/'.strtolower($this->site_name).'_'.date('Y-m-d').'.log';
            $this->scraper_log = './log/'.strtolower($this->site_name).'.log';
        }
        if (file_exists($this->scraper_log)) {
            unlink($this->scraper_log);
        }

        if (!isset($this->from_page)) {
            $this->from_page = 1;
        }

        // Start log
        $this->logg('---------------');
        $this->logg('Scraper started');
        $this->logg('Site name: '.$this->site_name);
        $this->logg('Log name: '.$this->scraper_log);
        $this->logg('Currency code: '.$this->currency_code);

        // Setup default CURL options
        $this->curl_options = [
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/68.0.3440.106 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_MAXREDIRS => 5
        ];
        
        // Connect to DB
        if (!$this->nodb) {
            $this->logg('Connect to DB');
            $this->db  = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);

            $this->get_site_id();       // Get site ID from DB
            $this->get_currency_id();   // Get currency ID from DB
        }

        // Init DOM
        $this->dom = new DOMDocument();
    }

    /**
     * @brief SaleScraper class destructor
     * @details Stop the scrapper
     */
    public function __destruct()
    {
        $this->logg('Scraper stopped');
    }

    /**
     * @brief Write log into console and log file
     * @param string $text      [Log message]
     * @param int $error_level  [Error level: 0 - message, 1 - warning, 2 - error]
     */
    public function logg($text, $error_level = 0)
    {
        switch ($error_level)
        {
            case 0: $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL; break;
            case 1: $log = '[' . @date('Y-m-d H:i:s') . '] WARNING: ' . $text . PHP_EOL; break;
            case 2: $log = '[' . @date('Y-m-d H:i:s') . '] ERROR: ' . $text . PHP_EOL; break;
            default: $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL; break;
        }
        echo $log;
        file_put_contents($this->scraper_log, $log , FILE_APPEND | LOCK_EX);
    }

    /**
     * @brief Run regexp on a string and return the result
     * @param string $regex     [Regex string]
     * @param string $input     [Input string for regexp]
     * @param int $output       [0 - return matches array, 1 - return matched value]
     */
    public function regex($regex, $input, $output = 0)
    {
        $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
        if (!$match)
            $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
        return $match;
    }

    /**
     * @brief Add custom cURL options
     * @param array $options    [Array of cURL options to add]
     */
    public function set_curl_options($options)
    {
        $this->logg('Set custom CURL options');
        foreach ($options as $key => $value) {
            $this->curl_options[$key] = $value;
        }
    }

    /**
     * @brief Remove custom cURL options
     * @param array $options    [Array of cURL options to remove]
     */
    public function unset_curl_options($options)
    {
        $this->logg('Unset custom CURL options');
        foreach ($options as $key => $value) {
            unset($this->curl_options[$key]);
        }
    }

    /**
     * @brief Set cURL proxy options
     */
    public function set_proxy()
    {
        $this->logg('Set CURL proxy');
        $options = [
            CURLOPT_PROXY => $this->proxy_addrport,
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
            CURLOPT_PROXYUSERPWD => $this->proxy_userpwd
        ];
        $this->use_proxy = true;
        $this->set_curl_options($options);
    }

    /**
     * @brief Remove cURL proxy options
     */
    public function unset_proxy()
    {
        $this->logg('Unset CURL proxy');
        $options = [
            CURLOPT_PROXY => '',
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
            CURLOPT_PROXYUSERPWD => ''
        ];
        $this->use_proxy = false;
        $this->set_curl_options($options);
    }

    /**
     * @brief Use TOR proxy
     */
    public function use_tor()
    {
        $this->logg('Use TOR proxy');
        $options = [
            CURLOPT_PROXY => '127.0.0.1:9050',
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5
        ];
        $this->use_proxy = true;
        $this->set_curl_options($options);
    }

    /**
     * @brief Set cookies usage on into cURL
     * @param string $cookies_file  [Cookies file name]
     */
    public function set_cookies($cookies_file = '')
    {
        if ($cookies_file == '') $cookies_file = strtolower($this->site_name).'_cookies.txt';
        else $cookies_file = strtolower($this->site_name).'_'.str_replace('/', '_', $cookies_file).'_cookies.txt';
        $this->logg('Set cookies: '.$cookies_file);
        $this->cookies_file = './cookies/'.$cookies_file;
        $this->set_curl_options([
            CURLOPT_COOKIEFILE => $this->cookies_file,
            CURLOPT_COOKIEJAR => $this->cookies_file
        ]);
    }

    /**
     * @brief Set cookies usage off into cURL
     */
    public function unset_cookies()
    {
        $this->logg('Unset cookies');
        $this->unset_curl_options([CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '']);
    }

    /**
     * @brief Load HTML page
     * @param string $url   [Page URL for loading]
     */
    public function get_page($url)
    {
        $html = -1;
        $i = 1;
        while ($html == -1)
        {
            $html = $this->curl($url);
            $i++;
            if ($i > $this->curl_max_attempts) {    // Max attempts retried, stop loading and goto next page
                sleep(60);     // Delay before retry
                break;
            }
        }
        if ($html == -1) 
        {
            $this->logg("Max attempts (".$this->curl_max_attempts.") retried: ".$url, 2);
            return false;
        }
        else
            return $html;
    }

    /**
     * @brief CURL increase errors count
     */
    private function inc_curl_errors()
    {
        $this->curl_errors++;
        if ($this->curl_errors > $this->curl_max_errors)
        {
            $this->logg('Too many CURL errors. Stop scrapper', 2);
            exit();
        }
    }

    /**
     * @brief CURL decrease errors count
     */
    private function  dec_curl_errors()
    {
        $this->curl_errors--;
        if ($this->curl_errors < 0) $this->curl_errors = 0;
    }
        
    /**
     * @brief CURL GET request
     * @param string $url   [Page URL for loading]
     */
    public function curl($url)
    {
        $url = preg_replace('#^//#', 'http://', $url);
        if (strpos($url, 'http') === false)
            $url = $this->baseurl.$url;
        $this->logg("Get page $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $this->curl_options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
            if (!in_array($info['http_code'], $this->curl_allowed_codes)) {
                $this->inc_curl_errors();   // Increase CURL errors count
                return -1;                  // Not allowed http code
            }
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 2);
            $this->inc_curl_errors();   // Increase CURL errors count
            return -1;                  // CURL error
        }
        curl_close($ch);
        $this->dec_curl_errors();
        return $html;
    }

    /**
     * @brief cURL POST request
     * @param string $url           [Page URL for loading]
     * @param array $postfields     [Array of post fields]
     */
    public function post($url, $postfields)
    {
        $postfields_string = '';
        foreach ($postfields as $key => $value)
            $postfields_string .= urlencode($key).'='.urlencode($value).'&';
        $postfields_string = substr($postfields_string, 0, -1);
        //var_dump($postfields_string);

        $options = $this->curl_options;
        $options = $options + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields_string];

        $url = preg_replace('#^//#', 'http://', $url);
        if (strpos($url, 'http') === false)
            $url = $this->baseurl.$url;

        $this->logg("Send POST $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
            //var_dump($info);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 2);
            return -1;
        }
        curl_close($ch);
        return $html;
    }

    public function post2($url, $postfields)
    {
        $postfields_string = $postfields;
        //var_dump($postfields_string);

        $options = $this->curl_options;
        $options = $options + [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postfields_string];

        $url = preg_replace('#^//#', 'http://', $url);
        if (strpos($url, 'http') === false)
            $url = $this->baseurl.$url;

        $this->logg("Send POST $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
            var_dump($info);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 2);
            return -1;
        }
        curl_close($ch);
        return $html;
    }

    /**
     * @brief Get DOM from HTML
     */
    public function loadDOM($html)
    {
        $this->dom->loadHTML($html);                // Load html to DOM
        $this->xpath = new DOMXPath($this->dom);    // Init XPATH
    }

    /**
     * @brief Search for XPath node and get value
     * @param string $query           [XPath for search]
     * @param node|bool $element      [Search in element, false - search in whole DOM]
     * @return bool|string            [Node value, false - node not found]
     */
    public function get_node_value($query, $element = false)
    {
        $value = false;
        if (!$element) {    // Search in all document
            foreach ($this->xpath->query($query) as $node) {
                $value = trim($node->nodeValue);
                break;
            }
        }
        else {    // Search in specified element only
            foreach ($this->xpath->query($query, $element) as $node) {
                $value = trim($node->nodeValue);
                break;
            }
        }
        return $value;
    }

    /**
     * @brief Search for XPath node and get attribute
     * @param string $query           [XPath for search]
     * @param string $attr            [Attribute for search]
     * @param node|bool $element      [Search in element, false - search in whole DOM]
     * @return bool|string            [Attribute value, false - node not found]
     */
    public function get_attribute($query, $attr, $element = false)
    {
        $attribute = false;
        if (!$element) {    // Search in all document
            foreach ($this->xpath->query($query) as $node) {
                $attribute = $node->getAttribute($attr);
                break;
            }
        }
        else {  // Search in specified element only
            foreach ($this->xpath->query($query, $element) as $node) {
                $attribute = $node->getAttribute($attr);
                break;
            }
        }
        return $attribute;
    }

    /**
     * @brief Get site ID and site URL from DB
     */
    private function get_site_id()
    {
        $this->logg('Get site ID for '.$this->site_name);
        $query = "SELECT DISTINCT * FROM sites WHERE site_name = :site_name";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['site_name'] = $this->site_name;
        if (!$req->execute($arr))
        {
            $this->logg('DB error');
            $this->logg(json_encode($req->errorInfo()), 2);
            exit();
        }
        $row = $req->fetch();
        if (!isset($row['site_id']))
        {
            $this->logg('No site name found in DB: '.$this->site_name, 2);
            exit();
        }
        else {
            $this->site_id = $row['site_id'];
            $this->logg('Site ID: '.$this->site_id);
            $this->baseurl = $row['site_url'];
            $this->logg('Site URL: '.$this->baseurl);
        }
    }

    /**
     * @brief Get currency id from DB and exchange rate from API
     * @details For currencies different from ZAR
     */
    private function get_currency_id()
    {
        $this->logg('Get currency ID for '.$this->currency_code);
        $query = "SELECT currency_id FROM currencies WHERE currency_code = :currency_code";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['currency_code'] = $this->currency_code;
        if (!$req->execute($arr))
        {
            $this->logg('DB error');
            $this->logg(json_encode($req->errorInfo()), 2);
            exit();
        }
        $row = $req->fetch();
        if (!isset($row['currency_id']))
        {
            $this->logg('No currency code found in DB: '.$this->currency_code, 2);
            exit();
        }
        else $this->currency_id = $row['currency_id'];
        // Get exchange rate from API
        if ($this->currency_code != 'ZAR') {
            $url = 'http://free.currencyconverterapi.com/api/v5/convert?q='.$this->currency_code.'_ZAR&compact=y&apiKey=2ecb2be6f35028f4e8e2';
            $this->logg('Get exchange rate from: '.$url);
            $json = file_get_contents($url);
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('JSON '.json_last_error_msg(), 2);
                $this->logg($json);
                exit();
            }
            if (!isset($arr[$this->currency_code.'_ZAR']['val']))
            {
                $this->logg('No exchange rate found', 2);
                $this->logg($json);
                exit();
            }
            $exchange_rate = $arr[$this->currency_code.'_ZAR']['val'];
            if ($exchange_rate <= 0)
            {
                $this->logg('Negative or zero exchange rate', 2);
                $this->logg($json);
                exit();
            }
            $this->exchange_rate = $exchange_rate;
        }
        else $this->exchange_rate = 1;  // No exchange for ZAR

        // Update exchange rate in DB
        $query = "UPDATE currencies SET latest_exchange_rate=:exchange_rate WHERE currency_code = :currency_code";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['exchange_rate'] = $this->exchange_rate;
        $arr['currency_code'] = $this->currency_code;
        if (!$req->execute($arr))
        {
            $this->logg('DB error', 2);
            $this->logg(json_encode($req->errorInfo()));
            exit();
        }
        $this->logg('Currency ID: '.$this->currency_id);
        $this->logg('Exchange rate: '.$this->exchange_rate);
    }

    /**
     * @brief Write item details into DB with errors count
     * @param array $item   [Array of fields to write]
     */
    public function write_item_details($item)
    {
        if (!$this->write_to_db($item)) $this->db_errors++;
        $this->items_count++;
        if ($this->items_count >= $this->db_max_items)
        {
            $this->items_count = 0;
            $this->db_errors = 0;
        }
        if ($this->db_errors >= $this->db_max_errors)
        {
            $this->logg('Too many DB errors. Stop scrapper', 2);
            exit();
        }
    }

    /**
     * @brief Filter text field before write to db
     * @details Remove prohibited symbols
     * @param string $text  [Text for filtering]
     * @return string       [Filtered text]
     */
    public function filter_text($text)
    {
        $text = preg_replace('#[^\w^\d^\.^:^-]#', ' ', $text);
        $text = preg_replace('#\s+#', ' ', $text);
        $text = preg_replace('#[\r\n]#', ' ', $text);
        $text = trim($text);
        return $text;
    }

    /**
     * @brief Write item details into DB
     * @param array $item   [Array of fields to write]
     * @return boolean
     */
    private function write_to_db($item)
    {   
        $this->logg('Write item details into DB');
        $dump = '';
        foreach ( $item as $key => $value )
            $dump .= $key . ': ' . $value . ' ';
        //$this->logg($dump);
        
        // Get brand id
        $query = "SELECT DISTINCT brand_id FROM brands WHERE brand_name = :brand_name";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['brand_name'] = $item['brand'];
        if (!$req->execute($arr))
        {
            $this->logg('DB error', 2);
            $this->logg(json_encode($req->errorInfo()));
            return false;
        }
        $row = $req->fetch();
        if (!isset($row['brand_id']))
        {
            $this->logg('No brand name found in DB. Adding brand: '.$item['brand']);
            $query = "INSERT INTO brands (brand_name) VALUES (:brand_name) ON DUPLICATE KEY UPDATE brand_name=VALUES(brand_name)";
            $req = $this->db->prepare($query);
            $arr = [];
            $arr['brand_name'] = $item['brand'];
            if (!$req->execute($arr))
            {
                $this->logg('DB error', 2);
                $this->logg(json_encode($req->errorInfo()));
                return false;
            }
            $brand_id = $this->db->lastInsertId();
            if ($brand_id == 0)
            {
                $this->logg('Error while brand inserting (brand_id=0)', 2);
                return false;
            }
        }
        else $brand_id = $row['brand_id'];
        
        if (!$this->debug)
        {
            $products_table = 'product_tracking';
        }
        else
        {
            $products_table = 'product_debug';
            $this->logg('Debug mode on. Write into product_debug table', 1);
        }

        $query = "INSERT INTO ".$products_table." (tracking_date, site_id, brand_id, product_name, product_description, product_id, sku_id, product_url, product_image1, product_image2, product_image3, product_image4, category, product_type, product_department, product_subcategory, currency_price, currency_id, price, sale_price, discount, product_option, qty_available) VALUES (:tracking_date, :site_id, :brand_id, :product_name, :product_description, :product_id, :sku_id, :product_url, :product_image1, :product_image2, :product_image3, :product_image4, :category, :product_type, :product_department, :product_subcategory, :currency_price, :currency_id, :price, :sale_price, :discount, :product_option, :qty_available) ON DUPLICATE KEY UPDATE tracking_date=VALUES(tracking_date), site_id=VALUES(site_id), brand_id=VALUES(brand_id), product_name=VALUES(product_name), product_description=VALUES(product_description), product_url=VALUES(product_url), product_image1=VALUES(product_image1), product_image2=VALUES(product_image2), product_image3=VALUES(product_image3), product_image4=VALUES(product_image4), category=VALUES(category), product_type=VALUES(product_type), product_department=VALUES(product_department), product_subcategory=VALUES(product_subcategory), currency_price=VALUES(currency_price), currency_id=VALUES(currency_id), price=VALUES(price), sale_price=VALUES(sale_price), discount=VALUES(discount), product_option=VALUES(product_option), qty_available=VALUES(qty_available)";
        $req = $this->db->prepare($query);

        $arr = [];
        $arr['site_id'] = $this->site_id;
        $arr['brand_id'] = $brand_id;
        $arr['currency_id'] = $this->currency_id;
        $arr['tracking_date'] = $item['tracking_date'];
        $arr['product_name'] = $this->filter_text($item['product_name']);
        $arr['product_description'] = '';
        if (isset($item['product_description']))
            $arr['product_description'] = $this->filter_text($item['product_description']);
        if (strlen($arr['product_description']) > 1024) $arr['product_description'] = substr($arr['product_description'], 0, 1023);        
        $arr['product_id'] = $item['product_id'];
        $arr['sku_id'] = $item['sku_id'];
        $arr['product_url'] = $item['product_url'];
        $arr['product_image1'] = '';
        if (isset($item['product_image1']))
            $arr['product_image1'] = $item['product_image1'];
        $arr['product_image2'] = '';
        if (isset($item['product_image2']))
            $arr['product_image2'] = $item['product_image2'];
        $arr['product_image3'] = '';
        if (isset($item['product_image3']))
            $arr['product_image3'] = $item['product_image3'];
        $arr['product_image4'] = '';
        if (isset($item['product_image4']))
            $arr['product_image4'] = $item['product_image4'];
        $arr['category'] = $item['category'];
        $arr['product_type'] = $this->filter_text($item['product_type']);
        $arr['product_subcategory'] = $this->filter_text($item['product_subcategory']);
        $arr['product_department'] = $this->filter_text($item['product_department']);
        $arr['currency_price'] = $item['price'];
        $arr['price'] = round($item['price'] * $this->exchange_rate, 2);
        $arr['sale_price'] = round($item['sale_price'] * $this->exchange_rate, 2);
        $arr['discount'] = $item['discount'];
        $arr['product_option'] = $item['product_option'];
        if ($item['qty_available'] == 0) {
            //$this->logg('No qty_available found', 1);
            $arr['qty_available'] = 0;
        }
        else
            $arr['qty_available'] = $item['qty_available'];
        //var_dump($arr);

        if (!$req->execute($arr))
        {
            $this->logg('DB error', 2);
            $this->logg(json_encode($req->errorInfo()));
            return false;
        }
        else
            return true;

    }

    /**
     * @brief Write products total for site into DB
     * @param int $products_total   [total products count from the site]
     * @return boolean
     */
    public function write_products_total($products_total)
    {
        $this->logg('Write products total [site ID: ' + $this->site_id + ' products total: ' + $products_total + ']');
        $query = "UPDATE sites SET products_total='$products_total' WHERE site_id='$this->site_id'";
        $req = $this->db->query($query);
        if (!$req)
        {
            $this->logg('DB error');
            $this->logg(json_encode($this->db->errorInfo()));
            exit();
        }
    }

}
