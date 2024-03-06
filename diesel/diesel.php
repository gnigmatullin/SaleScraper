<?php
/**
 * @file nextdirect.php
 * @brief File contains https://diesel.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class DieselScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class DieselScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Diesel', 'ZAR', $arg);
        $this->set_cookies();
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'men/jeans',
            'men/apparel',
            'men/shoes',
            'men/accessories',
            'woman/jeans',
            'woman/apparel',
            'woman/shoes',
            'woman/accessories'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category);
        }
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

        $pages_count = $this->get_node_value('//div[ @class = "pages" ]/ol/li[ last()-1 ]/a');
        if (!$pages_count)
        {
            $this->logg('No pages count found', 2);
            return false;
        }
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'?p='.$page);
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
        // Get all items
        foreach ($this->xpath->query('//h2[ @class = "product-name" ]/a') as $node)
        {
            $link = $node->getAttribute("href");
            $this->parse_item($link);
            if ($this->debug) break;
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

        // Product ID
        $item['product_id'] = $this->regex('#"productId":"(\d+)"#', $html, 1);
        if (!$item['product_id'])
        {
            $this->logg('No product ID found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }
        $this->product_id = $item['product_id'];
        
        // Product name
        $item['product_name'] = $this->get_node_value('//h2[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }

        // Price
        $price = $this->get_node_value('//span[ contains(@id, "product-price") ]');
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
        $original_price = $this->get_node_value('//span[ contains(@id, "old-price") ]');
        if ($original_price != '')
        {
            $original_price = $this->regex('#([\d\,\.]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
        {
            $this->logg('No discount found');
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->category;

        // Product type
        $item['product_type'] = '';

        // Get add to cart URL
        $item['add_cart_url'] = $this->get_attribute('//form[ @id = "product_addtocart_form" ]', 'action');
        //$item['add_cart_url'] = 'https://diesel.co.za/checkout/cart/add/uenc/aHR0cHM6Ly9kaWVzZWwuY28uemEvbWhhcmt5LTAwNzZrLTMzMjQ,/product/3324/form_key/0nzgmVp0vP2sXAPK/';
        // Form key
        $item['form_key'] = $this->get_attribute('//input[ @name = "form_key" ]', 'value');

        // Get sizes
        $json = $this->regex('#var\sspConfig\s=\snew\sProduct\.Config\((\{[\s\S]+?\})\);#', $html, 1);
        $arr = json_decode($json, true);
        foreach ($arr['attributes'] as $attribute) {
            foreach ($attribute['options'] as $option) {
                // Product option
                $item['product_option'] = $option['label'];
                // Add option to product ID
                $item['product_id'] = $this->product_id.'_'.$item['product_option'];
                // Filter out duplicates by ID
                if ( in_array($item['product_id'], $this->product_ids) )
                {
                    $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
                    return;
                }
                $this->product_ids[] = $item['product_id'];

                $item['attribute_id'] = $attribute['id'];

                $item['option_id'] = $option['id'];

                // Get quantity
                $this->add_to_cart($item);
                $item['qty_available'] = $this->get_qty($item);
                $this->clear_cart($item);

                // Write to DB
                $this->write_item_details($item);
            }
        }
    }

    // Add item to cart (need to get quantity)
    function add_to_cart($item)
    {
        $this->logg('Add to cart');
        //form_key=9e6desumPfdWsnqc&product=3324&related_product=&super_attribute%5B194%5D=15&qty=1
        $postfields = [
            'form_key' => $item['form_key'],
            'product' => $this->product_id,
            'super_attribute['.$item['attribute_id'].']' => $item['option_id'],
            'qty' => 1
        ];
        //$postfields = 'form_key=0nzgmVp0vP2sXAPK&product=3324&related_product=&super_attribute%5B194%5D=15&qty=1';
        //$this->set_curl_options([CURLOPT_FOLLOWLOCATION => false]);
        $html = $this->post($item['add_cart_url'], $postfields);
        //var_dump($html);
        $this->loadDOM($html);
        // Get all cart items
        $res = false;
        foreach ($this->xpath->query('//p[ @class = "product-name" ]/a') as $node) {
            $link = $node->getAttribute('href');
            //var_dump($link);
            if ($link == $item['product_url']) $res = true;
        }
        return $res;
    }

    // Update item qty in cart
    function update_item_qty($item)
    {
        $this->logg('Update item qty');
        $postfields = [
            'form_key' => $item['form_key'],
            'update_cart_action' => 'update_qty',
            $item['qty_holder'] => $item['qty']
        ];
        //var_dump($postfields);
        $html = $this->post('https://diesel.co.za/checkout/cart/updatePost/', $postfields);
        //var_dump($html);
        if (strpos($html, 'The requested quantity for') !== false) {
            $this->logg('Qty '.$item['qty'].' is not available');
            return false;
        }
        else {
            $this->logg('Qty is updated to '.$item['qty']);
            return true;
        }
    }

    // Get qty available
    function get_qty($item)
    {
        $html = $this->get_page('https://diesel.co.za/checkout/cart');
        $this->logg('Get item qty');
        //var_dump($html);
        $this->loadDOM($html);
        // Form key
        $item['form_key'] = $this->get_attribute('//input[ @name = "form_key" ]', 'value');
        // Qty holder
        $item['qty_holder'] = $this->get_attribute('//div[ @class = "qty-holder" ]/input', 'name');
        // Increase item qty until available (or to 10 maximum)
        for ($i = 2; $i < 10; $i++) {
            $item['qty'] = $i;
            if (!$this->update_item_qty($item))
                break;
        }
        $item['qty_available'] = $i-1;
        $this->logg('Item qty_available: '.$item['qty_available']);
        return $item['qty_available'];
    }

    // Clear cart
    function clear_cart($item)
    {
        $this->logg('Clear cart');
        $postfields = [
            'form_key' => $item['form_key'],
            'update_cart_action' => 'empty_cart',
            $item['qty_holder'] => $item['qty']
        ];
        //var_dump($postfields);
        $html = $this->post('https://diesel.co.za/checkout/cart/updatePost/', $postfields);
        //var_dump($html);
        if (strpos($html, "Shopping Cart is Empty") !== false) {
            $this->logg('Cart is empty');
            return true;
        }
        else {
            $this->logg('Clearing cart error');
            return false;
        }
    }


}

$scraper = new DieselScraper($argv);