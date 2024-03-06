<?php
/**
 * @file brandalley.php
 * @brief File contains https://www.labels4less.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Labels4LessScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class Labels4LessScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Labels4Less', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            't-shirts-vests'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/collections/'.$this->category);
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
        // Get pages count
        $items_count = $this->get_node_value('//div[ contains(@class, "product-count") ]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            return false;
        }
        $items_count = $this->regex('#(\d+)\sitems#', $items_count, 1);
        $pages_count = ceil($items_count / 60);
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
        foreach ( $this->xpath->query('//a[ @class = "product-name" ]') as $node )
        {
            $link = $node->getAttribute("href");
            $this->parse_item($link);
            //if ($this->debug) break;
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
        $item['brand'] = $this->get_attribute('//a[ @itemprop = "brand" ]/meta[ @itemprop = "name" ]', 'content');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $this->get_node_value('//span[ @id = "our_price_display" ]');
        if ($price)
        {
            $price = $this->regex('#([\d\.,]+)#', $price, 1);
            $item['price'] = str_replace('.', '', $price);
            $item['price'] = str_replace(',', '.', $item['price']);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Original price
        $original_price = $this->get_node_value('//span[ @id = "old_price_display" ]');
        if ($original_price != '')
        {
            $original_price = $this->regex('#([\d\.,]+)#', $original_price, 1);
            $item['original_price'] = str_replace('.', '', $original_price);
            $item['original_price'] = str_replace(',', '.', $item['original_price']);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->regex('#-(\w+?)$#', $this->category, 1);
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = '';

        // Product options
        $json = $this->regex('#var\scombinations=(\{[\s\S]+?\});#', $html, 1);
        $options = json_decode($json, true);
        foreach ($options as $id => $option) {

            // Product ID
            $item['product_id'] = $id;
            if (!$item['product_id'])
            {
                $this->logg('No product ID');
                $this->logg('HTML: '. $html, 2);
                exit();
            }

            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID '.$item['product_id']. ' already scraped');
                return;
            }
            $this->product_ids[] = $item['product_id'];

            // Product option
            foreach ($option['attributes_values'] as $attribute_value) {
                $item['product_option'] = $attribute_value;
                break;
            }

            // Quantity
            $item['qty_available'] = $option['quantity'];

            if ($item['qty_available'] != 0)
                $this->write_item_details($item);

        }

    }

}

$scraper = new Labels4LessScraper($argv);