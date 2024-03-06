<?php
/**
 * @file zando.php
 * @brief File contains https://www.zando.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class ZandoScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class ZandoScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Zando', 'ZAR', $arg);
        $this->set_proxy();
        $this->set_cookies($this->category);
        $this->parse_catalog($this->baseurl.'/'.$this->category);
    }

    /**
     * @brief Custom POST request
     * @param string $url
     * @param array  $postfields
     * @return int|mixed
     */
    function post($url, $postfields)
    {
        $options = $this->curl_options;
        $options = $options + [
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
        ];
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

        $items_count = $this->get_node_value('//span[ @class = "total-products" ]');
        if (!$items_count)
        {
            $this->logg('No items count found', 2);
            return false;
        }
        $items_count = $this->regex('#(\d+)#', $items_count, 1);
        $this->logg('Items count: '.$items_count);
        $pages_count = ceil($items_count / 40);
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

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
        foreach ( $this->xpath->query('//section[ @class = "products" ]/div/a') as $node )
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
        
        // Brand
        $item['brand'] = $this->get_node_value('//div[ contains(., "Brand:") ]/a');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }
        
        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "-fs20 -pts -pbxs" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }
        
        // Product ID
        $item['product_id'] = $this->get_attribute('//input[ @name = "simpleSku" ]', 'value');
        if (!$item['product_id'])
        {
            $this->logg('No product ID', 2);
            //$this->logg('HTML: '. $html);
            return;
        }
        
        // csrf token
        $item['csrf_token'] = $this->get_attribute('//input[ @name = "csrfToken" ]', 'value');
        if (!$item['csrf_token'])
        {
            $this->logg('No csrf token found', 2);
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
        $price = $this->get_node_value('//span[ @class = "-b -ltr -tal -fs24" ]');
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
        
        // Discount
        $discount = $this->get_node_value('//span[ @class = "tag _dsct _dyn -mls" ]');
        $item['discount'] = str_replace('%', '', $discount);
        $item['descount'] = $this->regex('#([\d\.]+)#', $item['discount'], 1);
        if (!$item['discount'])
        {
            $item['discount'] = 0;
        }

        // Category and product type
        $nodes = $this->xpath->query('//div[ @class = "brcbs col16 -pts -pbm" ]/a');
        $i = 0;
        foreach ( $nodes as $node )
        {
            switch ($i)
            {
                case 1: $item['category'] = trim($node->nodeValue); break;
                case 2: $item['product_type'] = trim($node->nodeValue); break;
            }
            $i++;
        }
        if (!isset($item['category']) || !isset($item['product_type']))
        {
            $this->logg('No section or sub section found', 2);
            //$this->logg('HTML: '.$html);
            return false;
        }

        // Get sizes
        $json = $this->regex('#window.__STORE__=(\{[\s\S]+?\});#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json');
            $this->logg(json_last_error_msg());
            return false;
        }
        foreach ($arr['simples'] as $size_id => $size)
        {
            if (!$size['isBuyable'])
                continue;
            $item['product_option'] = $size['name'];
            $item['size_id'] = $size_id;
            if (!isset($this->items[$item['size_id']])) $this->add_to_cart($item);
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
        $this->logg('Add item to cart');
        $postfields = 'simpleSku='.$item['size_id'].'&csrfToken='.$item['csrf_token'];
        $this->logg('Post fields: '.$postfields);
        $this->post($this->baseurl.'/ajax/cart/add/?return=json&output=new', $postfields);
        $this->items[$item['size_id']] = $item;
    }

    // Parse items quantity from cart
    function parse_cart()
    {
        $html = $this->get_page($this->baseurl.'/cart');
        $this->logg('Parse items from cart');
        $this->loadDOM($html);
        $nodes = $this->xpath->query('//form[ @class = "product ft-product" ]');
        foreach ( $nodes as $node )
        {
            $size_id = '';
            $cols = $this->xpath->query('.//input[ @name = "sku" ]', $node);
            foreach ( $cols as $col )
            {
                $size_id = $col->getAttribute('value');
                break;
            }
            if ($size_id == '') {
                $this->logg('No size ID found. Skip item');
                continue;
            }
            $this->logg('Size ID: '.$size_id);
            $cols = $this->xpath->query('.//div[ contains( @class, "qty-overlay") ]/a', $node);
            foreach ( $cols as $col )
            {
                $quantity = $col->nodeValue;
            }
            if (!$quantity)
            {
                $this->logg('No quantity selector found', 1);
                $quantity = 1;
                continue;
            }
            $this->logg('Quantity: '.$quantity);
            if (!isset($this->items[$size_id]))
            {
                $this->logg('No product with size ID '.$size_id. ' found. Skip item', 1);
                continue;
            }
            $this->items[$size_id]['qty_available'] = $quantity;
        }
    }
}

$scraper = new ZandoScraper($argv);