<?php
/**
 * @file hushpuppies.php
 * @brief File contains https://www.hushpuppies.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class HushPuppiesScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class HushPuppiesScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('HushPuppies', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'womens-footwear',
            'mens-footwear'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/index.php/'.$this->category);
            if ($this->debug) break;
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
        $this->category_name = $this->get_node_value('//h1');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Get pages count
        $items_count = $this->get_node_value('//div[ contains(@class, "display-number") ]');
        if (!$items_count)
        {
            $this->logg('No items count found', 1);
            $pages_count = 1;
        }
        else {
            $items_count = $this->regex('#of\s(\d+)#', $items_count, 1);
            $pages_count = ceil($items_count / 24);
        }
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $start = ($page - 1) * 24;
            $page_html = $this->get_page($url.'?start='.$start);
            $this->logg('Parse catalogue page '.$page);
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
        preg_match_all('#prodDetail\d+\.url\s=\s\"(\S+)\"#', $html, $matches);
        if (!isset($matches[1]))
        {
            $this->logg('No items found', 2);
            exit();
        }
        foreach ($matches[1] as $link)
        {
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
        $item['product_url'] = $this->baseurl.$url;

        // Brand
        $item['brand'] = $this->site_name;

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        $item['product_name'] = $item['product_name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            return;
        }

        // Price
        $price = $this->get_node_value('//span[ contains(@class, "price-sales") ]');
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
        $original_price = $this->get_node_value('//span[ @class = "price-standard" ]');
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
        $item['product_type'] = trim($this->get_node_value('//div[ @class = "breadcrumb" ]/a[4]'));
        if (!$item['product_type'])
        {
            $this->logg('No product type found', 1);
            $item['product_type'] = '';
        }

        // Variants
        if (!preg_match('#variants:\[\["[\s\S]+?\]\]#', $html))
        {
            $this->logg('No variants found', 2);
            return;
        }
        $json = $this->regex('#variants:(\[\["[\s\S]+?\]\])#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            return;
        }
        foreach ($arr as $variant)
            $this->get_variant($item, $variant);
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
        $item['product_id'] = $variant[0];
        if ($item['product_id'] == '') {
            $this->logg('No product ID found', 2);
            $this->logg('JSON: '.json_encode($variant));
            exit();
        }
        $item['product_id'] = $this->baseurl.$item['product_id'];
        // Filter out duplicates by ID
        if (in_array($item['product_id'], $this->product_ids)) {
            $this->logg('Product ID '.$item['product_id'].' already scraped');
            return;
        }
        $this->product_ids[] = $item['product_id'];

        // Product option
        if ($variant[1] === '0') { // Skip empty options
            return;
        }
        $item['product_option'] = $variant[1].' '.$variant[2];

        $this->write_item_details($item);

    }

}

$scraper = new HushPuppiesScraper($argv);