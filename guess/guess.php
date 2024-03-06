<?php
/**
 * @file nextdirect.php
 * @brief File contains https://www.guess.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class GuessScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class GuessScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Guess', 'ZAR', $arg);
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
            'women/apparel',
            'women/accessories',
            'women/shoes',
            'men/apparel',
            'men/accessories',
            'men/shoes'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category.'.html');
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
        $item['product_name'] = $this->get_node_value('//div[ @class = "product-name" ]/span');
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

        // Form key
        $item['form_key'] = $this->get_attribute('//input[ @name = "form_key" ]', 'value');

        // Get sizes
        $json = $this->regex('#var\sspConfig\s=\snew\sProduct\.Config\((\{[\s\S]+?\})\);#', $html, 1);
        $arr = json_decode($json, true);

        $attributes = [];
        foreach ($arr['attributes'] as $attr_id => $attribute) {
            $i = 0;
            foreach ($attribute['options'] as $option) {
                $attributes[$attr_id]['attr_id'] = $attribute['id'];
                $attributes[$attr_id]['options'][$i]['option_id'] = $option['id'];
                $attributes[$attr_id]['options'][$i]['option_label'] = $option['label'];
                $attributes[$attr_id]['options_cnt'] = $i;
                $max = $i;
                $i++;
            }
        }
        //var_dump($attributes);

        foreach ($attributes as $attr1_id => $attribute1) {
            foreach ($attribute1['options'] as $option1) {
                foreach ($attributes as $attr2_id => $attribute2) {
                    if ($attr1_id == $attr2_id) continue;
                    foreach ($attribute2['options'] as $option2) {
                        /*
                        echo $attr1_id."\r\n";
                        echo $option1['option_id']."\r\n";
                        echo $attr2_id."\r\n";
                        echo $option2['option_id']."\r\n";
                        echo "\r\n";
                        */
                        $postfields = [
                            'form_key' => $item['form_key'],
                            'product' => $this->product_id,
                            'super_attribute['.$attr1_id.']' => $option1['option_id'],
                            'super_attribute['.$attr2_id.']' => $option2['option_id'],
                            'qty' => 1,
                        ];
                        //var_dump($postfields);

                        // Product option
                        $item['product_option'] = trim($option1['option_label'].' '.$option2['option_label']);
                        // Add option to product ID
                        $item['product_id'] = $this->product_id.'_'.$item['product_option'];
                        $item['product_id'] = str_replace(' ', '_', $item['product_id']);

                        // Filter out duplicates by ID
                        if (in_array($item['product_id'], $this->product_ids)) {
                            $this->logg('Product ID '.$item['product_id'].' already scraped', 1);
                            return;
                        }
                        $this->product_ids[] = $item['product_id'];

                        // Get quantity
                        $this->add_to_cart($item['add_cart_url'], $postfields);
                        $item['qty_available'] = $this->get_qty($item);
                        $this->clear_cart($item);

                        // Write to DB
                        $this->write_item_details($item);
                    }
                }
            }
            break;
        }

    }

    // Add item to cart (need to get quantity)
    function add_to_cart($url, $postfields)
    {
        $this->logg('Add to cart');
        $html = $this->post($url, $postfields);
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
        $html = $this->post('https://www.guess.co.za/checkout/cart/updatePost/', $postfields);
        // Open cart
        $html = $this->get_page('https://www.guess.co.za/checkout/cart');
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
        $html = $this->get_page('https://www.guess.co.za/checkout/cart');
        $this->logg('Get item qty');
        $this->loadDOM($html);
        // Form key
        $item['form_key'] = $this->get_attribute('//input[ @name = "form_key" ]', 'value');
        // Qty holder
        $item['qty_holder'] = $this->get_attribute('//input[ @class = "input-text qty" ]', 'name');
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
        $html = $this->get_page('https://www.guess.co.za/checkout/cart');
        $this->logg('Clear cart');
        //https://www.guess.co.za/checkout/cart/delete/id/49903/form_key/qOzVJFlmmWtUrEG0/uenc/aHR0cHM6Ly93d3cuZ3Vlc3MuY28uemEvY2hlY2tvdXQvY2FydC8,/
        $this->loadDOM($html);
        $link = $this->get_attribute('//a[ contains(@href, "/cart/delete/id/") ]', 'href');
        $html = $this->get_page($link);
        /*$html = $this->get_page('https://www.guess.co.za/checkout/cart');
        //var_dump($html);
        if (strpos($html, "Shopping Cart is Empty") !== false) {
            $this->logg('Cart is empty');
            return true;
        }
        else {
            $this->logg('Clearing cart error');
            return false;
        }*/
    }


}

$scraper = new GuessScraper($argv);