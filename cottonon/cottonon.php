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
        $items_count = preg_replace('#[^\d,]#', '', $items_count);
        $items_count = $this->regex('#([\d,\s]+)#', $items_count, 1);
        $items_count = str_replace(' ', '', $items_count);
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
        $item['brand'] = $this->regex('#\"brand\":\"(.+?)\"#', $html, 1);
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

        // Category
        $item['category'] = $this->get_node_value('//a[ @class = "breadcrumb-element last-category" ]');
        if (!$item['category'])
        {
            $this->logg('No category found', 1);
            //$this->logg('HTML: '. $html);
            return false;
        }
        if (!isset($item['category'])) $item['category'] = '';
        if (!isset($item['product_type'])) $item['product_type'] = '';
            
        $nodes = $this->xpath->query('//span[ @id = "selected-color-value" ]');
        foreach ( $nodes as $node )
        {
            $item['product_colour'] = $node->nodeValue;
            break;
        }
        if ( !isset($item['product_colour']) )
        {
            $this->logg('No product color found');
            return;
        }
        $item['color_id'] = $this->regex('#originalPid=([\d-]+)#', $url, 1);
        $item['product_id'] = $this->regex('#originalPid=(\d+)#', $url, 1);
        $item['supplier_sku'] = $item['product_id'];

        // Product details
        $item['product_details'] = $this->get_node_value('//div[ @id = "features-tab" ]');
        if (!$item['product_details'])
        {
            $this->logg('No product details found', 1);
            $item['product_details'] = '';
        }
        $item['product_details'] = $this->filter_text($item['product_details']);

        // Thumbnail image
        $item['thumbnail_image'] = $this->get_attribute('//img[ @id = "thumbnailimage_0" ]', 'src');
        if (!$item['thumbnail_image'])
        {
            $this->logg('No thumbnail image found', 1);
            $item['thumbnail_image'] = '';
        }

        // Product image 1
        $item['product_image1'] = $this->get_attribute('//img[ @id = "thumbnailimage_0" ]', 'src');
        if (!$item['product_image1'])
        {
            $this->logg('No product image found', 1);
            $item['product_image1'] = '';
        }
        $item['product_image1'] = preg_replace('#\?.*$#', '', $item['product_image1']);

        // Product image 2
        $item['product_image2'] = $this->get_attribute('//img[ @id = "thumbnailimage_1" ]', 'src');
        if (!$item['product_image2'])
        {
            $this->logg('No product image found', 1);
            $item['product_image2'] = '';
        }
        $item['product_image2'] = preg_replace('#\?.*$#', '', $item['product_image2']);

        // Product image 3
        $item['product_image3'] = $this->get_attribute('//img[ @id = "thumbnailimage_2" ]', 'src');
        if (!$item['product_image3'])
        {
            $this->logg('No product image found', 1);
            $item['product_image3'] = '';
        }
        $item['product_image3'] = preg_replace('#\?.*$#', '', $item['product_image3']);

        // Product image 4
        $item['product_image4'] = $this->get_attribute('//img[ @id = "thumbnailimage_3" ]', 'src');
        if (!$item['product_image4'])
        {
            $this->logg('No product image found', 1);
            $item['product_image4'] = '';
        }
        $item['product_image4'] = preg_replace('#\?.*$#', '', $item['product_image4']);

        // Get sizes
        $nodes = $this->xpath->query('///ul[ contains(@class, "size") ]/li/a[@href]');
        foreach ($nodes as $node) {
            $item['product_size'] = $node->getAttribute('data-size');
            $this->add_to_cart($item);
        }
        
        // Get quantities from cart
        $this->parse_cart();

        // Save results
        foreach ($this->items as $id => $item)
        {
            // Product ID
            $item['product_id'] = $id;

            // Filter out duplicates by ID
            if (in_array($item['product_id'], $this->product_ids)) {
                $this->logg('Product ID '.$item['product_id'].' already scraped');
                continue;
            }
            $this->product_ids[] = $item['product_id'];

            // Product option
            $item['product_option'] = $item['product_colour'].' - '.$item['product_size'];

            if ($item['qty_available'] != 0) {
                $this->write_item_details($item);
            }
        }
        // Delete cookies (empty cart)
        unlink($this->cookies_file);
        // Clear items
        $this->items = [];
    }

    /**
     * @brief Add item to the cart
     * @details Requred to get quantity
     * @param array $item   [Array of item details]
     */
    private function add_to_cart($item)
    {
        $this->logg('Add item to cart ID: '.$item['product_id'].' size: '.$item['product_size']);
        $url = $this->baseurl.'/ZA/show-variation/?pid='.$item['product_id'].'&dwvar_'.$item['product_id'].'_color='.$item['color_id'].'&dwvar_'.$item['product_id'].'_size='.$item['product_size'].'&originalPid='.$item['color_id'].'&Quantity=1&format=ajax';
        $html = $this->get_page($url);
        //var_dump($html);
        $this->loadDOM($html);
        $link = $this->get_node_value('//span[ @itemprop = "url" ]');
        $pid = $this->regex('#(\d+)\.html#', $link, 1);

        $postfields = 'Quantity=1&cartAction=add&pid='.$pid;
        $this->logg('Post fields: '.$postfields);
        $this->post($this->baseurl.'/on/demandware.store/Sites-cog-za-Site/en_ZA/Cart-AddProduct?format=ajax', $postfields);
        $this->items[$item['color_id'].$item['product_size']] = $item;
    
    }

    /**
     * Parse items quantity from the cart
     */
    function parse_cart()
    {
        $html = $this->get_page($this->baseurl.'/ZA/bag');
        $this->logg('Parse items from cart');
        $this->loadDOM($html);
        $nodes = $this->xpath->query('//div[ @class = "row product-line-row" ]');
        foreach ( $nodes as $node )
        {
            $cols = $this->xpath->query('.//div[ contains( @class, "product-title") ]/a[ @class = "product-name" ]', $node);
            foreach ( $cols as $col )
            {
                $url = $col->getAttribute('href');
                break;
            }
            //$this->logg('Product URL: '.$url);
            $color_id = $this->regex('#color=([\d\-]+)#', $url, 1);
            //$this->logg('Product color ID: '.$color_id);
            $size = $this->regex('#size=([^&]+)#', urldecode($url), 1);
            //$this->logg('Product size: '.$size);
            $cols = $this->xpath->query('.//select/option', $node);
            foreach ( $cols as $col )
            {
                $quantity = trim($col->nodeValue);
            }
            //$this->logg('Quantity: '.$quantity);
            if (!isset($this->items[$color_id.$size]))
            {
                $this->logg('No product found');
                continue;
            }
            $this->items[$color_id.$size]['qty_available'] = $quantity;
        }
    }

}

$scraper = new CottonOnScraper($argv);