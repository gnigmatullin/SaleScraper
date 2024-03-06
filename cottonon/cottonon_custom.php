<?php
/**
 * @file cottonon.php
 * @brief File contains https://cottonon.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class CottonOnScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class CottonOnScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('CottonOn', 'ZAR', $arg);
        $this->set_cookies($this->category);
        $this->parse_catalog($this->baseurl.'/ZA/'.$this->category);
    }

    /**
     * @brief Custom POST request
     * @details POST request to add item to the cart (required to get quantity)
     * @param string $url
     * @param array  $postfields
     * @return int|mixed
     */
    function post($url, $postfields)
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['x-requested-with: XMLHttpRequest']
        );
        $this->logg("Send POST $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 1);
            return -1;
        }
        curl_close($ch);
        return $html;
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse catalogue '.$url);
        $this->loadDOM($html);

        $items_count = $this->get_node_value('//span[ @class = "paging-information-items" ]');
        if (!$items_count)
        {
            $this->logg('No items count found', 1);
            return false;
        }
        $items_count = $this->regex('#([\d,]+)#', $items_count, 1);
        $items_count = str_replace(',', '', $items_count);
        $pages_count = ceil($items_count/48);
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $start = ($page-1)*48;
            $page_html = $this->get_page($url.'?sz=48&start='.$start);
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($page_html);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($html)
    {
        $this->loadDOM($html);
        foreach ( $this->xpath->query('//a[ @class = "name-link" ]') as $node )
        {
            $link = $node->getAttribute("href");
            $this->parse_item($link);
            //if ($this->>debug) break;
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse item details from '.$url);
        $this->loadDOM($html);
        
        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $url;
        
        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            //$this->logg('HTML: '. $html);
            return false;
        }
        
        // Brand
        $item['brand'] = $this->get_node_value('//div[ @id = "pdpMain" ]/div[ contains(@class, "product-detail") ]/div');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 1);
            //$this->logg('HTML: '. $html);
            return false;
        }
        
        // Discount
        $discount = $this->get_node_value('//span[ @class = "percentage-save" ]');
        $item['discount'] = $this->regex('#([\d,]+)#', $discount, 1);
        if (!$item['discount'])
        {
            $item['discount'] = 0;
        }
        
        // Price
        $price = $this->get_node_value('//span[ @class = "price-sales" ]');
        if ($price)
        {
            $price = $this->regex('#([\d,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Category and product type
        $nodes = $this->xpath->query('//ul[ @class = "breadcrumbs row" ]/li');
        $i = 0;
        foreach ( $nodes as $node )
        {
            $cols = $this->xpath->query('./a', $node);
            foreach ( $cols as $col )
            {
                switch ($i)
                {
                    case 1: $item['category'] = trim($col->nodeValue); break;
                    case 2: $item['product_type'] = trim($col->nodeValue); break;
                }
            }
            $i++;
        }
        if (!isset($item['category']) && !isset($item['product_type']))
        {
            $this->logg('No category and product type found', 1);
            //$this->logg('HTML: '.$html);
            return false;
        }
        if (!isset($item['category'])) $item['category'] = '';
        if (!isset($item['product_type'])) $item['product_type'] = '';

        // Product ID
        $item['product_id'] = $this->get_node_value('//span[ @class = "product-code" ]');
        if (!$item['product_id'])
        {
            $this->logg('No product_id found', 1);
            //$this->logg('HTML: '. $html);
            return false;
        }

        // Product description
        $item['product_description'] = $this->get_node_value('//div[ @class = "product-content" ]');
        if (!$item['product_description'])
        {
            $this->logg('No product_description found', 1);
            //$this->logg('HTML: '. $html);
            return false;
        }
        $item['product_description'] =  $this->filter_text($item['product_description']);

        $item['product_option'] = '';
        $item['qty_available'] = 0;

        $this->write_item_details($item);
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
        $this->logg($dump);

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

        $query = "INSERT INTO product_debug (tracking_date, site_id, brand_id, product_name, product_description, product_id, product_url, category, product_type, currency_price, currency_id, price, discount, product_option, qty_available, end_date) VALUES (:tracking_date, :site_id, :brand_id, :product_name, :product_description, :product_id, :product_url, :category, :product_type, :currency_price, :currency_id, :price, :discount, :product_option, :qty_available, :end_date) ON DUPLICATE KEY UPDATE tracking_date=VALUES(tracking_date), site_id=VALUES(site_id), brand_id=VALUES(brand_id), product_name=VALUES(product_name), product_description=VALUES(product_description), product_url=VALUES(product_url), category=VALUES(category), product_type=VALUES(product_type), currency_price=VALUES(currency_price), currency_id=VALUES(currency_id), price=VALUES(price), discount=VALUES(discount), product_option=VALUES(product_option), qty_available=VALUES(qty_available), end_date=VALUES(end_date)";
        $req = $this->db->prepare($query);

        $arr = [];
        $arr['site_id'] = $this->site_id;
        $arr['brand_id'] = $brand_id;
        $arr['currency_id'] = $this->currency_id;
        $arr['tracking_date'] = $item['tracking_date'];
        $arr['product_name'] = $this->filter_text($item['product_name']);
        $arr['product_description'] = $this->filter_text($item['product_description']);
        $arr['product_id'] = $item['product_id'];
        $arr['product_url'] = $item['product_url'];
        $arr['category'] = $item['category'];
        $arr['product_type'] = $item['product_type'];
        $arr['currency_price'] = $item['price'];
        $arr['price'] = round($item['price'] * $this->exchange_rate, 2);
        $arr['discount'] = $item['discount'];
        $arr['product_option'] = $item['product_option'];
        if ($item['qty_available'] == 0) {
            $this->logg('No qty_available found', 1);
            $arr['qty_available'] = 0;
        }
        else
            $arr['qty_available'] = $item['qty_available'];
        $arr['end_date'] = $item['end_date'];

        if (!$req->execute($arr))
        {
            $this->logg('DB error', 2);
            $this->logg(json_encode($req->errorInfo()));
            return false;
        }
        return true;
    }

}

$scraper = new CottonOnScraper($argv);