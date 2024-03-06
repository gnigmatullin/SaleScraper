<?php
/**
 * @file firstascent.php
 * @brief File contains https://www.firstascent.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class FirstAscentScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class FirstAscentScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('FirstAscent', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'mens/jackets',
            'mens/tops',
            'mens/bottoms',
            'mens/accessories',
            'ladies/jackets',
            'ladies/tops',
            'ladies/bottoms',
            'ladies/accessories',
            'junior/clothing'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category);
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
        $pages_count = $this->get_node_value('//ol[ @class = "pagination left" ]/li[ last()-1 ]/a');
        if (!$pages_count)
        {
            $this->logg('No pages count found');
            $pages_count = 1;
        }
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        // Get pages
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
        // Get items
        foreach ( $this->xpath->query('//h5[ @class = "product-name" ]/a') as $node )
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

        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @itemprop = "name" ]/h1');
        $item['product_name'] = $item['product_name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $this->get_node_value('//span[ @class = "price" ]');
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

        // Discount
        $item['discount'] = 0;

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = '';

        // Variants
        if (!preg_match('#jsonData\s=\s\{[\s\S]+?\};#', $html))
        {
            $this->logg('No variants found', 2);
            exit();
        }
        $json = $this->regex('#jsonData\s=\s(\{[\s\S]+?\});#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            exit();
        }

        foreach ($arr as $variant_id => $variant) {
            // Product ID
            $item['product_id'] = $variant_id;
            if ($item['product_id'] == '') {
                $this->logg('No product ID found', 2);
                $this->logg('JSON: '.$json);
                exit();
            }
            // Filter out duplicates by ID
            if (in_array($item['product_id'], $this->product_ids)) {
                $this->logg('Product ID '.$item['product_id'].' already scraped');
                return;
            }
            $this->product_ids[] = $item['product_id'];

            // Product option
            $item['product_option'] = $variant['size'];

            // Quantity
            $item['qty_available'] = $variant['qty'];
            if ($item['qty_available'] != 0)
                $this->write_item_details($item);
        }
    }

}

$scraper = new FirstAscentScraper($argv);