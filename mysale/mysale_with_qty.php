<?php
require_once 'db.php';

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

// Scraper class
class MysaleScraper
{
    var $site_name = 'MySale';
    var $site_id;
    var $currency_code = 'GBP';
    var $currency_id;
    var $category;
    var $category_id;
    var $exchange_rate;
    var $scraper_log;
    var $cookies = [];
    var $db; // PDO
    var $product_ids = [];  // IDs list to avoid duplicates
    var $items = [];
    var $items_count = 0;
    var $errors_count = 0;
    
    public function __construct($category)
    {
        global $db_servername;
        global $db_username;
        global $db_password;
        global $db_database;
        $this->logg('---------------');
        $this->logg('Scraper started');
        $this->logg('Connect to DB');
        $this->db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);
        if (!isset($category)||($category==''))
        {
            $this->logg('No category specified');
            exit();
        }
        // women-clothing/V29tZW4_Pj5DbG90aGluZw==
        if (!preg_match('#^\S+\/#', $category) || !preg_match('#\/\S+$#', $category))
        {
            $this->logg('Wrong category format: '.$category);
            exit();
        }
        $this->category = $this->regex('#^(\S+)\/#', $category, 1);
        $this->logg('Category: '.$this->category);
        $this->category_id = $this->regex('#\/(\S+)$#', $category, 1);
        $this->logg('Category ID: '.$this->category_id);
        $this->scraper_log = 'mysale_'.str_replace('-', '_', $this->category).'.log';
        $this->logg('Scraper log: '.$this->scraper_log);
        if (file_exists($this->scraper_log)) unlink($this->scraper_log);
        $this->get_site_id();
        $this->get_currency_id();
        $this->login();
        //$this->get_quantities();
        //exit();
        $this->parse_catalog();
        $this->logg("Scraper stopped");
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
    
    // POST request
    function post($url, $postfields)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['content-type: application/json']
        );
        
        $cookies_string = '';
        foreach ($this->cookies as $name => $value)
        {
            $cookies_string .= $name.'='.$value.'; ';
        }
        $cookies_string = substr($cookies_string, 0, -1);
        if ($cookies_string != '')
        {
            $options[CURLOPT_HTTPHEADER][] = 'Cookie: '.$cookies_string;
        }
        
        $this->logg("Send POST $url");
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
            return [$info['http_code'], $html];
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err);
            return false;
        }
        curl_close($ch);
        return false;
    }

    // Get site id
    private function get_site_id()
    {
        $this->logg('Get site ID for '.$this->site_name);
        $query = "SELECT DISTINCT site_id FROM sites WHERE site_name = :site_name";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['site_name'] = $this->site_name;
        if (!$req->execute($arr))
        {
            $this->logg('DB error');
            $this->logg(json_encode($req->errorInfo()));
            exit();
        }
        $row = $req->fetch();
        if (!isset($row['site_id']))
        {
            $this->logg('No site name found in DB: '.$this->site_name);
            exit();
        }
        else $this->site_id = $row['site_id'];
    }

    // Get currency id and exchange rate (for currencies different of ZAR)
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
            $this->logg(json_encode($req->errorInfo()));
            exit();
        }
        $row = $req->fetch();
        if (!isset($row['currency_id']))
        {
            $this->logg('No currency code found in DB: '.$this->currency_code);
            exit();
        }
        else $this->currency_id = $row['currency_id'];
        // Get exchange rate from API
        if ($this->currency_code != 'ZAR') {
            $json = file_get_contents('http://free.currencyconverterapi.com/api/v5/convert?q='.$this->currency_code.'_ZAR&compact=y');
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('Error in json: '.json_last_error_msg());
                $this->logg($json);
                exit();
            }
            if (!isset($arr[$this->currency_code.'_ZAR']['val']))
            {
                $this->logg('No exchange rate found');
                $this->logg($json);
                exit();
            }
            $exchange_rate = $arr[$this->currency_code.'_ZAR']['val'];
            if ($exchange_rate <= 0)
            {
                $this->logg('Negative or zero exchange rate');
                $this->logg($json);
                exit();
            }
            $this->exchange_rate = $exchange_rate;
        }
        // Update exchange rate in DB
        $query = "UPDATE currencies SET latest_exchange_rate=:exchange_rate WHERE currency_code = :currency_code";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['exchange_rate'] = $this->exchange_rate;
        $arr['currency_code'] = $this->currency_code;
        if (!$req->execute($arr))
        {
            $this->logg('DB error');
            $this->logg(json_encode($req->errorInfo()));
            exit();
        }
        $this->logg('Currency ID: '.$this->currency_id);
        $this->logg('Exchange rate: '.$this->exchange_rate);
    }
    
    // Login on the site
    private function login()
    {
        $this->logg('Login to website');
        $postfields = '{"username":"joeschmoshopper@gmail.com","password":"joeschmo123"}';
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['content-type: application/json']
        );
        $cookies_string = '';
        foreach ($this->cookies as $name => $value)
        {
            $cookies_string .= $name.'='.$value.'; ';
        }
        $cookies_string = substr($cookies_string, 0, -1);
        if ($cookies_string != '')
        {
            $options[CURLOPT_HTTPHEADER][] = 'Cookie: '.$cookies_string;
        }
        $url = 'https://www.mysale.co.uk/api/shop/auth/v1/accounts/314D2B32-21F9-431D-975C-129BE4ED6BA0/users/me/tokens';
        $this->logg("Send POST $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        $result = false;
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
            //var_dump ($info);
            if ($info['http_code'] == 200)
            {
                // Get cookies
                preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $html, $matches);
                foreach($matches[1] as $item)
                {
                    parse_str($item, $cookie);
                    $this->cookies = array_merge($this->cookies, $cookie);
                }
                $result = true;
            }
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err);
        }
        curl_close($ch);
        if (!$result)
        {
            $this->logg('Login error');
            exit();
        }
        $this->logg('Login success');
    }
    
    // Add to cart
    private function add_to_cart($skuId)
    {
        $this->logg('Add to cart '.$skuId);
        $postfields = '{"skuId":"'.$skuId.'","personalizationData":null}';
        $result = $this->post('https://www.mysale.co.uk/api/shop/product/v1/accounts/314D2B32-21F9-431D-975C-129BE4ED6BA0/basket/items', $postfields);
        if ($result[0] != 201)
        {
            $this->logg('Add to cart error');
            exit();
        }
        $this->logg('Success added');
    }
    
    // Increase order item
    private function increase_order_item($skuId)
    {
        $this->logg('Increase order item '.$skuId);
        $postfields = '{"itemID":"'.$skuId.'","imageSize":200}';
        $result = $this->post('https://www.mysale.co.uk/ApacService.asmx/IncreaseOrderItem', $postfields);
        if ($result[0] != 200)
        {
            $this->logg('Increase order item error');
            exit();
        }
        $arr = json_decode($result[1], true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg());
            $this->logg($result[1]);
            exit();
        }
        return $arr['d']['Result'];
    }
    
    // Get quantities
    private function get_quantities()
    {
        $this->logg('Get item quantities for current order');
        $postfields = '{"imageSize":200}';
        $result = $this->post('https://www.mysale.co.uk/ApacService.asmx/GetCurrentOrder', $postfields);
        if ($result[0] != 200)
        {
            $this->logg('Get order error');
            exit();
        }
        $arr = json_decode($result[1], true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg());
            $this->logg($result[1]);
            exit();
        }
        $this->logg('Get item list from order');
        $cnt = 0;
        foreach ($this->items as $item_id => $item)
        {
            foreach ($arr['d']['Value']['Items'] as $order_item)
            {
                if (($item['product_name'] == $order_item['Item']) && ($item['option'] == $order_item['Size']))
                {
                    $this->items[$item_id]['order_id'] = $order_item['ID'];
                    $cnt++;
                }
            }
        }
        if ($cnt == 0)
        {
            $this->logg('No items found in order');
            exit();
        }
        $this->logg($cnt.' items found in order');
        foreach($this->items as $id => $item)
        {
            if (!isset($item['order_id'])) continue;
            $this->logg('Get quantity for item '.$item['order_id']);
            $count = 1;
            while ($count < 5)
            {
                if ($this->increase_order_item($item['order_id'])) $count++;
                else break;
            }
            $this->logg('Quantity: '.$count);
            $this->items[$id]['quantity'] = $count;
            break;
        }
    }

    // Parse catalog
    private function parse_catalog()
    {
        $this->logg('Parse category: '.$this->category);
        $category = str_replace('-', '>>>', $this->category);
        $url = 'https://www.mysale.co.uk/api/shop/shop/v2/accounts/314D2B32-21F9-431D-975C-129BE4ED6BA0/products?q=&pn=0&ps=50&c=["'.$category.'"]';
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error());
            $this->logg($json);
            return;
        }
        // Get pages count
        if (!isset($arr['total']))
        {
            $this->logg('No products count found');
            return;
        }
        $pages_count = ceil($arr['total'] / 50);
        $this->logg('Pages count: '.$pages_count);
        // Parse first page
        $this->logg('Parse catalogue page 0');
        $this->parse_catalog_page($arr);
        
        // Parse rest pages
        for ($page = 1; $page < $pages_count; $page++)
        {
            $json = $this->get_page(str_replace('&pn=0', '&pn='.$page, $url));
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('Error in json: '.json_last_error());
                $this->logg($json);
                continue;
            }
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($arr);
            break;
        }
    }
    
    // Parse catalog page
    private function parse_catalog_page($arr)
    {
        // Get all items
        foreach ($arr['products'] as $item){
            $this->parse_item($item);
            break;
        }
    }

    // Parse main item page
    private function parse_item($a)
    {
        $this->logg('Parse item details for productID: '.$a['seoIdentifier']);
        $url = 'https://www.mysale.co.uk/api/shop/product/v1/accounts/314D2B32-21F9-431D-975C-129BE4ED6BA0/products/'.$a['seoIdentifier'];
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error());
            $this->logg($json);
            return;
        }
        $item = [];
        $item['date'] = date('Y-m-d');
        $item['url'] = 'https://www.mysale.co.uk/shop/'.$this->category.'/'.$this->category_id.'/product/'.$arr['seoUrl'].'/s/'.$arr['seoIdentifier'];
        if (preg_match('#\S+-#', $this->category))
            $item['category'] = $this->regex('#(\S+)-#', $this->category, 1);
        else
        {
            $this->logg('No category found: '.$this->category);
            $item['category'] = '';
        }
        if (preg_match('#-\S+#', $this->category))
            $item['product_type'] = $this->regex('#-(\S+)#', $this->category, 1);
        else
        {
            $this->logg('No product type found: '.$this->category);
            $item['product_type'] = '';
        }

        // Brand
        $item['brand'] = $arr['brandName'];
        if ($item['brand'] == '')
        {
            $this->logg('No brand name found');
            $this->logg(json_encode($arr));
            exit();
        }
        
        // Get all variants
        foreach ($arr['skuVariants'] as $variant)
        {
            if ($variant['quantity'] == 0) continue;    // Skip sold
            // Product name
            $item['product_name'] = $variant['name'];
            if ($item['product_name'] == '')
            {
                $this->logg('No product name found');
                $this->logg(json_encode($arr));
                exit();
            }
            // Product ID
            $item['product_id'] = $variant['skuId'];
            if ($item['product_id'] == '')
            {
                $this->logg('No product ID');
                $this->logg(json_encode($arr));
                exit();
            }
            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID '.$item['product_id']. ' already scraped');
                return;
            }
            $this->product_ids[] = $item['product_id'];
            // Price
            $price = $variant['price']['value'];
            if ($price != '')
            {
                $price = $this->regex('#([\d\,\.]+)#', $price, 1);
                $item['price'] = str_replace(',', '', $price);
            }
            else
            {
                $this->logg('No price found');
                $item['price'] = '';
            }
            // Original price
            $original_price = $variant['originalPrice']['value'];
            if ($original_price != '')
            {
                $original_price = $this->regex('#([\d\,\.]+)#', $original_price, 1);
                $item['original_price'] = str_replace(',', '', $original_price);
                $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
            }
            else
            {
                $this->logg('No discount found');
                $item['discount'] = '';
            }
            // Option
            $item['option'] = trim($variant['attributes']['size']);
            if ($item['option'] == '')
            {
                $this->logg('No option found');
                $item['option'] = '';
            }
            $this->add_to_cart($item['product_id']);
            $this->items[$item['product_id']] = $item;
        }
        
        $this->get_quantities();
        $this->items = [];
    }

    // Write item details into google sheet
    private function write_item_details($item)
    {   
        $this->logg('Write item details into DB');
        $dump = '';
        foreach ( $item as $key => $value )
            $dump .= $key . ': ' . $value . ' ';
        $this->logg($dump);
        return;
        
        // Get brand id
        $query = "SELECT DISTINCT brand_id FROM brands WHERE brand_name = :brand_name";
        $req = $this->db->prepare($query);
        $arr = [];
        $arr['brand_name'] = $item['brand'];
        if (!$req->execute($arr))
        {
            $this->logg('DB error');
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
                $this->logg('DB error');
                $this->logg(json_encode($req->errorInfo()));
                return false;
            }
            $brand_id = $this->db->lastInsertId();
            if ($brand_id == 0)
            {
                $this->logg('Error while brand inserting (brand_id=0)');
                return false;
            }
        }
        else $brand_id = $row['brand_id'];

        $query = "INSERT INTO product_debug (tracking_date, site_id, brand_id, product_name, product_id, product_url, category, product_type, currency_price, currency_id, price, discount, product_option, qty_available) VALUES (:tracking_date, :site_id, :brand_id, :product_name, :product_id, :product_url, :category, :product_type, :currency_price, :currency_id, :price, :discount, :product_option, :qty_available) ON DUPLICATE KEY UPDATE tracking_date=VALUES(tracking_date), site_id=VALUES(site_id), brand_id=VALUES(brand_id), product_name=VALUES(product_name), product_url=VALUES(product_url), category=VALUES(category), product_type=VALUES(product_type), currency_price=VALUES(currency_price), currency_id=VALUES(currency_id), price=VALUES(price), discount=VALUES(discount), product_option=VALUES(product_option), qty_available=VALUES(qty_available)";
        $req = $this->db->prepare($query);
        
        $arr = [];
        $arr['tracking_date'] = $item['date'];
        $arr['product_name'] = $item['product_name'];
        $arr['product_id'] = $item['product_id'];
        $arr['product_url'] = $item['url'];
        $arr['category'] = $item['category'];
        $arr['product_type'] = $item['product_type'];
        $arr['currency_price'] = $item['price'];
        $arr['price'] = round($item['price'] * $this->exchange_rate, 2);
        $arr['discount'] = $item['discount'];
        $arr['product_option'] = $item['option'];
        $arr['qty_available'] = '';
        $arr['site_id'] = $this->site_id;
        $arr['brand_id'] = $brand_id;
        $arr['currency_id'] = $this->currency_id;
        
        if (!$req->execute($arr))
        {
            $this->logg('DB error');
            $this->logg(json_encode($req->errorInfo()));
            return false;
        }
    }

}

if (count($argv) < 2) 
{
    echo "No category name specified in the arguments\r\n";
    exit();
}
$mysale = new MysaleScraper($argv[1]);