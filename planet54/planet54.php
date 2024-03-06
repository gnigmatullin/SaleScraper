<?php
/**
 * @file planet54.php
 * @brief File contains https://planet54.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Planet54Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class Planet54Scraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Planet54', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'womens/mcat_footwear',
            'womens/mcat_clothing',
            'womens/mcat_accessories',
            'womens/mcat_health-beauty',
            'mens/mcat_footwear',
            'mens/mcat_clothing',
            'mens/mcat_accessories',
            'mens/mcat_health-beauty',
            'kids/subdept_boys',
            'kids/subdept_girls',
            'kids/subdept_infants',
            'kids/mcat_accessories'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/collections/'.$this->category);
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
        $pages_count = $this->get_node_value('//div[ @class = "pagination" ]/span[ last()-1 ]/a');
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
        foreach ( $this->xpath->query('//div[ @class = "product--grid-item" ]/a') as $node )
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
        $item['product_url'] = $this->baseurl.$url;

        // Brand
        $item['brand'] = $this->get_node_value('//p[ @class = "product--vendor" ]');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "product--title" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }

        // Price
        $price = $this->get_node_value('(//div[ @class = "product--price-wrapper" ]//span[ @class = "money" ])[1]');
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
        $original_price = $this->get_node_value('(//div[ @class = "product--price-wrapper" ]//span[ @class = "money" ])[2]');
        if ($original_price)
        {
            $original_price = $this->regex('#([\d\.,]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['original_price'] = round($original_price, 2);
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

        // Get additional details from json
        if (!preg_match('#window\.saso_extras\.product\s=\s(\{[\s\S]+?\});#', $html))
        {
            $this->logg('No variants found', 2);
            return;
        }
        $json = $this->regex('#window\.saso_extras\.product\s=\s(\{[\s\S]+?\});#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            return;
        }

        // Product type
        if (isset($arr['type']))
            $item['product_type'] = $arr['type'];
        else {
            $this->logg('No product type found');
            $item['product_type'] = '';
        }

        // Variants
        foreach ($arr['variants'] as $variant) {
            // Product ID
            $item['product_id'] = $variant['id'];
            if ($item['product_id'] == '') {
                $this->logg('No product ID found', 2);
                $this->logg('JSON: '.$json);
                return;
            }
            // Filter out duplicates by ID
            if (in_array($item['product_id'], $this->product_ids)) {
                $this->logg('Product ID '.$item['product_id'].' already scraped', 1);
                return;
            }
            $this->product_ids[] = $item['product_id'];

            // Product option
            $item['product_option'] = $variant['title'];

            // Quantity
            $item['qty_available'] = $variant['inventory_quantity'];
            if ($item['qty_available'] != 0)
                $this->write_item_details($item);
        }
    }

}

$scraper = new Planet54Scraper($argv);