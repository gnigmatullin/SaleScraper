<?php
/**
 * @file holsterfashion.php
 * @brief File contains https://www.holsterfashion.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class HolsterFashionScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class HolsterFashionScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('HolsterFashion', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'women/all-womens'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category);
            //if ($this->debug) break;
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

        // Category name
        unset($this->category_name);
        $this->category_name = $this->category;

        // Get pages count
        $pages_count = $this->get_node_value('//ul[ @class = "page-numbers" ]/li[ last()-1 ]/a');
        if (!$pages_count)
        {
            $this->logg('No pages count found', 1);
            $pages_count = 1;
        }
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'/page/'.$page);
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($page_html);
            //if ($this->debug) break;
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
        foreach ( $this->xpath->query('//a[ @class = "woocommerce-LoopProduct-link" ]') as $node )
        {
            $link = $node->getAttribute("href");
            $this->parse_item($link);
            //if ($this->debug) break;
            sleep(3);
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
        
        // Product id
        $item['product_id'] = $this->get_node_value('//span[ @class = "sku" ]');
        if (!$item['product_id'])
        {
            $this->logg('No product id found', 2);
            //$this->logg('HTML: '. $html);
            //exit();
            //return;
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        $item['product_name'] = $item['product_name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            return;
        }

        // Price
        $price = $this->get_node_value('//p[ @class = "price" ]/ins/span');
        if (!$price) $price = $this->get_node_value('//p[ @class = "price" ]/span');
        if ($price)
        {
            $price = $this->regex('#([\d\.,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Original price
        $original_price = $this->get_node_value('//p[ @class = "price" ]/del/span');
        if ($original_price != '')
        {
            $original_price = $this->regex('#([\d\.,]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            return;
        }

        // Product type
        $item['product_type'] = $this->get_node_value('//nav[ @class = "woocommerce-breadcrumb" ]/a[3]');
        if (!$item['product_type'])
        {
            //$this->logg('No product type found', 1);
            $item['product_type'] = '';
        }
        
        // Product option
        $item['product_option'] = $this->get_node_value('//div[ ./span[contains(., "Colour:") ]]');
        
        // Image URL
        $item['image_url'] = $this->get_attribute('//meta[ @property = "og:image" ]', 'content');
        if (!$item['image_url'])
        {
            $this->logg('No image URL found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        
        // Write to DB
        $this->write_item_details($item);

        // Variants
        /*if (!preg_match('#data-product_variations=\"[\s\S]+?\"#', $html))
        {
            $this->logg('No variants found', 2);
            return;
        }
        $json = $this->regex('#data-product_variations=\"([\s\S]+?)\"#', $html, 1);
        $json = str_replace("&quot;", '"', $json);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            return;
        }
        foreach ($arr as $variant)
            $this->get_variant($item, $variant);*/
        
    }

    /**
     * @brief Get variant details
     * @details Get variant details from array and write to DB
     * @param array $item       [Array of item details]
     * @param array $variant    [Array of variant details]
     */
    private function get_variant($item, $variant)
    {
        // Product ID
        $item['product_id'] = $variant['variation_id'];
        if ($item['product_id'] == '') {
            $this->logg('No product ID found', 2);
            $this->logg('JSON: '.json_encode($variant));
            exit();
        }
        // Filter out duplicates by ID
        if (in_array($item['product_id'], $this->product_ids)) {
            $this->logg('Product ID '.$item['product_id'].' already scraped');
            return;
        }
        $this->product_ids[] = $item['product_id'];

        // Product option
        foreach ($variant['attributes'] as $attribute) {
            $item['product_option'] = $attribute;   // Get first attribute
            break;
        }

        // Quantity
        $item['qty_available'] = $variant['max_qty'];
        if ($item['qty_available'] != 0)
            $this->write_item_details($item);
    }

}

$scraper = new HolsterFashionScraper($argv);