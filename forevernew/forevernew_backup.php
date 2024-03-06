<?php
require_once '../vendor/autoload.php';
require_once '../lib/google_functions.php';

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

define('APPLICATION_NAME', 'Forever New App');
define('CREDENTIALS_PATH', 'token.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
define('SCOPES', implode(' ', array(
        Google_Service_Sheets::SPREADSHEETS,
        Google_Service_Drive::DRIVE,
        Google_Service_Drive::DRIVE_FILE,
        Google_Service_Drive::DRIVE_APPDATA,
        Google_Service_Drive::DRIVE_READONLY,
        Google_Service_Drive::DRIVE_METADATA,
        Google_Service_Drive::DRIVE_METADATA_READONLY
    )
));

// Scraper class
class ForevernewScraper
{

    var $scraper_log = "forevernew.log";
    
    var $client;            // Google sheets API client
    var $service;           // Google sheets API service
    var $spreadsheetId;
    
    var $dom;               // For parse HTML
    var $xpath;
    
    var $product_ids = [];  // IDs list to avoid duplicates
    
    public function __construct()
    {
        $this->logg('---------------');
        $this->logg('Scraper started');
        // Create a new file based on template
        $client = getClient();
        $service       = new Google_Service_Drive($client);
        $copy          = copyFile($service, '1YHy_2VXoJn-tY46msVhU2TkYqcw1X7ar0fdSq91VNXo', 'Forever New '.date('Y-m-d'));
        $this->spreadsheetId = $copy->id;
        $this->logg('Spreadsheet ID: '.$this->spreadsheetId);
        $this->client = getClient();
        $this->service = new Google_Service_Sheets($this->client);
        
        $this->dom = new DOMDocument();
        
        // Parse all categories
        $categories = ['new-in', 'dresses', 'clothing', 'accessories', 'shoes', 'sale'];
        foreach ($categories as $category)
        {
            $this->parse_catalog('https://www.forevernew.co.za/index.php/'.$category.'?p=1&ajax=1');
            //break;
        }
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
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30
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
        $arr = json_decode($html, true);
        if (!isset($arr['product_list']))
        {
            $this->log('Product list not found');
            $this->logg('HTML: '.$html);
            return;
        }
        $products_count = $this->regex('#(\d+)\sproducts#', $arr['product_list'], 1);
        if (!$products_count)
        {
            $this->logg('No products count found');
            $this->logg('HTML: '.$html);
            return;
        }
        $pages_count = ceil($products_count / 12);
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse item links from catalogue page: 1');
        $this->parse_catalog_page($html);
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_url = str_replace('?p=1', '?p='.$page, $url);
            $html = $this->get_page($page_url);
            $this->logg('Parse item links from catalogue page: '.$page);
            $this->parse_catalog_page($html);
        }
    }

    // Parse catalog page html
    // Process all items
    private function parse_catalog_page($html)
    {
        $arr = json_decode($html, true);
        if (!isset($arr['product_list']))
        {
            $this->log('Product list not found');
            $this->logg('HTML: '.$html);
            return;
        }
        $this->loadDOM($arr['product_list']);
        foreach ( $this->xpath->query('//a[ @class = "product-image" ]') as $node )
        {
            $link = $node->getAttribute("href");
            //var_dump($link);
            //$link = 'https://www.forevernew.co.za/sale/marilyn-feather-jewelled-clutch';
            $this->parse_item($link);
            //break;
        }
    }

    // Parse main item page
    private function parse_item($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse item details from '.$url);
        $this->loadDOM($html);
        
        $item = array();
        $item['date'] = date('d.m.Y');
        $item['url'] = $url;
        $item['brand'] = 'Forever New';
        
        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @class = "product-name" ]/span');
        if (!$item['product_name'])
        {
            $this->logg('No product name found');
            $this->logg('HTML: '. $html);
            return;
        }
        
        // Product ID
        $item['product_id'] = $this->get_attribute('//input[ @name = "product" ]', 'value');
        if (!$item['product_id'])
        {
            $this->logg('No product ID');
            $this->logg('HTML: '. $html);
            return;
        }
        
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped');
            return;
        }
        $this->product_ids[] = $item['product_id'];
        
        // Price
        $price = $this->get_node_value('//span[ @class = "price" ]');
        if ($price)
        {
            $price = $this->regex('#([\d,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found');
            $item['price'] = 0;
        }
        
        // Special price
        $special_price = $this->get_node_value('//p[ @class = "special-price" ]/span[ @class = "price" ]');
        if ($special_price)
        {
            $special_price = $this->regex('#([\d,]+)#', $special_price, 1);
            $item['special_price'] = str_replace(',', '', $special_price);
            // Discount
            $item['discount'] = round( ($item['price'] - $item['special_price']) / $item['price'] * 100 );
        }
        else
        {
            $this->logg('No special price found');
            $item['special_price'] = 0;
            $item['discount'] = 0;
        }

        // Section
        $item['section'] = $this->get_node_value('//div[ @class = "breadcrumbs" ]/ul/li[ contains( @class, "category" ) ]/a');
        if (!$item['section'])
        {
            $this->logg('No section found');
            $item['section'] = '';
        }
        
        // Subsection
        $item['sub_section'] = '';
        
        // Get options
        $json = $this->regex('#var\sspConfig\s=\snew\sProduct\.Config\(({[\s\S]+?})\)#', $html, 1);
        if ($json)
        {
            $this->logg('Search for spconfig');
            $spconfig = json_decode($json, true);
            if (!isset($spconfig['attributes']))
            {
                $this->logg('No options attributes found');
                $this->logg('HTML: '.$html);
            }
            $options = [];
            foreach ( $spconfig['attributes'] as $attribute )
            {
                foreach ( $attribute['options'] as $opt )
                {
                    $option = [];
                    if (!isset($opt['label']))
                    {
                        $this->logg('No label found for option');
                        $this->logg('JSON: '.$json);
                        continue;
                    }
                    $option['label'] = $opt['label'];
                    if (!isset($opt['products'][0]))
                    {
                        $this->logg('No ID found for option');
                        $this->logg('JSON: '.$json);
                        continue;
                    }
                    $option['id'] = $opt['products'][0];
                    $options[] = $option;
                }
            }
            foreach ( $options as $key => $option )
            {
                $qty = $this->regex('#id\['.$option['id'].'\]\s=\s(\d+)#', $html, 1);
                if (!$qty)
                {
                    $this->logg('No quantity found for option: '.$option['id']);
                    continue;
                }
                $item['option'] = $option['label'];
                $item['quantity'] = $qty;
                //var_dump($item);
                $this->write_item($item);
            }
        }
        else
        {
            $this->logg('No options found. Search for quantity');
            foreach ( $this->xpath->query('//select[ @id = "qty" ]/option') as $node )
            {
                $qty = trim($node->getAttribute('value'));
            }
            if (!isset($qty)) $qty = 0;
            //var_dump($qty);
            $item['option'] = '';
            $item['quantity'] = $qty;
            //var_dump($item);
            $this->write_item($item);
        }

    }

    // Write item (5 attempts)
    private function write_item($item)
    {
        $res = -2;
        $i = 0;
        while ($res == -2)
        {
            $res = $this->write_item_details($item);
            $i++;
            if ($i > 4) break;
            sleep(1);
        }
    }
    
    // Write item details into google sheet
    private function write_item_details($item)
    {   
        $this->logg('Write item details into google sheet '.$this->spreadsheetId);
        $dump = '';
        foreach ( $item as $key => $value )
            $dump .= $key . ': ' . $value . ' ';
        $this->logg($dump);
        // Get the API client and construct the service object
        $this->logg('Add new row with item details into google sheet');
        $range  = 'Sheet1!A1:K1';
        $body   = new Google_Service_Sheets_ValueRange([
                                                            'values' => [
                                                                [
                                                                    $item['date'],
                                                                    $item['section'],
                                                                    $item['sub_section'],
                                                                    $item['product_name'],
                                                                    $item['discount'],
                                                                    $item['price'],
                                                                    $item['option'],
                                                                    $item['quantity'],
                                                                    $item['url'],
                                                                    $item['product_id']
                                                                ]
                                                            ]
                                                        ]
        );
        try
        {
            $result = $this->service->spreadsheets_values->append($this->spreadsheetId, $range, $body, ['valueInputOption' => 'RAW']);
            $upd    = $result->getUpdates();
            if ($upd != null)
                $this->logg($upd->updatedColumns.' cells written');
            else
                $this->logg('No cells written');
        }
        catch(Google_Exception $exception)
        {
            $this->logg('Google sheets API error: '.$exception->getMessage());
            return -2;
        }
        return 1;
    }

}

$forevernew = new ForevernewScraper();