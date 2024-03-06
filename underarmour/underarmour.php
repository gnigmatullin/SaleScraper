<?php
/**
 * @file dc.php
 * @brief File contains https://www.underarmour.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class UnderarmourScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Underarmour', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'mens' => '39',
            'womens' => '3c'
        ];
        // Get all categories
        foreach ($categories as $category_name => $category_id) {
            $this->category = $category_name;
            $this->logg('Category: '.$this->category);
            $this->category_id = $category_id;
            $this->parse_catalog('https://www.underarmour.com/en-za/api/json-grid/g/'.$this->category_id.'?offset=0&limit=60&stackId=other_grid_header&stackIdx=0&t=&hiddenProducts=0&initialOffset=0');
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
        $json = $this->get_page($url);
        $this->logg('Parse catalogue '.$url);
        $json = str_replace(")]}',", '', $json);
        $arr = json_decode($json, true);

        // Category name
        unset($this->category_name);
        $this->category_name = $arr['title'];
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $items_count = $arr['totalCount'];
        if (!$items_count)
        {
            $this->logg('No items count found');
            $pages_count = 1;
        }
        else
        {
            $pages_count = ceil($items_count / 60);
            $this->logg('Pages count: '.$pages_count);
        }
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($arr);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $offset = ($page-1) * 60;
            $json = $this->get_page('https://www.underarmour.com/en-za/api/json-grid/g/'.$this->category_id.'?offset='.$offset.'&limit=60&stackId=other_grid_header&stackIdx=0&t=&hiddenProducts=0&initialOffset=0');
            $this->logg('Parse catalogue page ' . $page);
            $json = str_replace(")]}',", '', $json);
            $arr = json_decode($json, true);
            $this->parse_catalog_page($arr);
            //if ($this->debug) break;
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($arr)
    {
        // Get items
        foreach ($arr['_embedded']['results'][0]['products'] as $product)
        {
            $this->parse_item($product);
            //if ($this->debug) break;
            sleep(2);
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($product)
    {
        $this->logg('Parse item details');

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $product['_links']['web:canonical']['href'];

        // Brand
        $item['brand'] = $this->site_name;
        
        // Product name
        $item['product_name'] = $product['content']['name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $product['priceRange']['base']['min'];
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
        
        // Get all options
        foreach ($product['materials'] as $material)
        {
            // Product id
            $item['product_id'] = $material['materialCode'];
            if (!$item['product_id'])
            {
                $this->logg('No product id found', 2);
                continue;
            }
        
            // Product option
            $item['product_option'] = $material['color']['primary']['name'];
            if (!$item['product_option'])
                $item['product_option'] = '';
            
            // Image URL
            $image_url = $material['assets'][0]['image'];
            if (!$image_url)
            {
                $this->logg('No image URL found', 2);
                continue;
            }
            $item['image_url'] = 'https://underarmour.scene7.com/is/image/Underarmour/'.$image_url;
    
            // Write to DB
            $this->write_item_details($item);
        }
    }

}

$scraper = new UnderarmourScraper($argv);