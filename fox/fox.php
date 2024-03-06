<?php
/**
 * @file fox.php
 * @brief File contains https://www.foxracing.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class FoxScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class FoxScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Fox', 'ZAR', $arg);
        $this->set_cookies($this->category);
        $this->parse_catalog($this->baseurl.'/'.$this->category.'/all-products/');
    }

    /**
     * @brief Custom POST request
     * @param string $url           [Page URL]
     * @param array  $postfields    [Request data]
     * @return int|mixed            [HTML code | -1 if error]
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
            $this->logg("CURL error ".curl_errno($ch)." ".$err);
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

        $pages_count = $this->get_node_value('//ul[ contains(@class, "pagination ") ]/li[ last()-1 ]/a');
        if (!$pages_count)
        {
            $this->logg('No pages count found', 1);
            $pages_count = 1;
        }
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        // Get all pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'?page='.$page);
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
        // Get items
        foreach ( $this->xpath->query('//div[ @class = "catalog--product-detail" ]/a') as $node )
        {
            $link = $this->baseurl.$node->getAttribute("href");
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
        
        // Brand
        $item['brand'] = $this->site_name;
        
        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "catalog-detail__product-name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }
        
        // YII_CSRF_TOKEN
        $item['csrf_token'] = $this->get_attribute('//input[ @name = "YII_CSRF_TOKEN" ]', 'value');
        if (!$item['csrf_token'])
        {
            $this->logg('No csrf token found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }

        // Product ID
        $item['product_id'] = $this->get_attribute('//input[ @name = "configSku" ]', 'value');
        if (!$item['product_id'])
        {
            $this->logg('No product ID', 2);
            //$this->logg('HTML: '. $html);
            return;
        }

        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
            return;
        }
        $this->product_ids[] = $item['product_id'];
        
        // Price
        $price = $this->get_attribute('//meta[ @itemprop = "price" ]', 'content');
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

        // Original price
        $original_price = $this->get_node_value('//div[ @class = "old-price catalog-detail__product-price" ]');
        if ($original_price)
        {
            $original_price = $this->regex('#([\d,]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Category and product type
        $nodes = $this->xpath->query('//ol[ @class = "breadcrumb" ]/li');
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
        if (!isset($item['category']) || !isset($item['product_type']))
        {
            $this->logg('No section or sub section found', 2);
            //$this->logg('HTML: '.$html);
            return false;
        }

        // Get sizes and add each to cart
        $nodes = $this->xpath->query('//ul[ @class = "prd-option-collection sizes clearfix" ]/li[ contains(@class, "inStock") ]');
        foreach ( $nodes as $node )
        {
            // Product option
            $item['product_option'] = $node->getAttribute('data-selenium');

            $item['product_option'] = preg_replace('#sel-details-size-#', '', $item['product_option']);

            // Size ID
            $item['size_id'] = $node->getAttribute('data-sku');

            if ( !isset($this->items[$item['size_id']]) ) $this->add_to_cart($item);
        }
        
        // Get quantities from cart
        $this->parse_cart();

        // Write items
        foreach ($this->items as $item)
            $this->write_item_details($item);

        // Delete cookies (empty cart)
        unlink($this->cookies_file);

        // Clear items
        $this->items = [];
    
    }

    // Add item to cart (need to get quantity)
    function add_to_cart($item)
    {
        $this->logg('Add item to cart');
        $date = urlencode(date('Y-m-d H:i:s A'));
        $postfields = 'p='.$item['product_id'].'&sku='.$item['size_id'].'&YII_CSRF_TOKEN='.$item['csrf_token'].'&html-date='.$date;
        $this->logg('Post fields: '.$postfields);
        $this->post($this->baseurl.'/cart/add/', $postfields);
        $this->items[$item['size_id']] = $item;
    }

    // Parse items quantity from cart
    function parse_cart()
    {
        $html = $this->get_page($this->baseurl.'/cart');
        $this->logg('Parse items from cart');
        $this->loadDOM($html);
        $nodes = $this->xpath->query('//div[ @class = "product-row" ]');
        foreach ( $nodes as $node )
        {
            $cols = $this->xpath->query('.//select[ contains( @class, "quantity-selector") ]', $node);
            foreach ( $cols as $col )
            {
                $size_id = $col->getAttribute('id');
                break;
            }
            $size_id = str_replace('qty_', '', $size_id);
            $this->logg('Size ID: '.$size_id);
            $cols = $this->xpath->query('.//select[ contains( @class, "quantity-selector") ]/option', $node);
            foreach ( $cols as $col )
            {
                $quantity = $col->getAttribute('value');
            }
            if (!$quantity)
            {
                $this->logg('No quantity found. Skip item', 1);
                continue;
            }
            $this->logg('Quantity: '.$quantity);
            if (!isset($this->items[$size_id]))
            {
                $this->logg('No product with size ID '.$size_id. ' found. Skip item', 1);
                continue;
            }
            // Quantity
            $this->items[$size_id]['qty_available'] = $quantity;
            // Update product ID with current size SKU
            $this->items[$size_id]['product_id'] = $size_id;
        }
    }
}

$scraper = new FoxScraper($argv);