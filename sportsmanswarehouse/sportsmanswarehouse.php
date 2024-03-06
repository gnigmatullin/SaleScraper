<?php
/**
 * @file sportsmanswarehouse.php
 * @brief File contains https://www.sportsmanswarehouse.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class SportsmansWarehouseScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class SportsmansWarehouseScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('SportsmansWarehouse', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_subcategories function for each category
     */
    private function parse_categories()
    {
        $url = $this->baseurl.'/category/sub/'.$this->category;
        $html = $this->get_page($url);
        $this->logg('Parse category '.$url);
        $this->loadDOM($html);

        // Get all subcategories
        foreach ( $this->xpath->query('//div[ contains(@class, "list_category") ]/a') as $node )
        {
            $url = $node->getAttribute('href');
            $this->parse_subcategories($url);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Parse subcategories
     * @details Run parse_catalog function for each subcategory
     */
    private function parse_subcategories($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse subcategory '.$url);
        $this->loadDOM($html);

        // Get all catalogues
        foreach ( $this->xpath->query('//div[ contains(@class, "list_category") ]/a') as $node )
        {
            $url = $node->getAttribute('href');
            $this->parse_catalog($url);
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
        // Get pages count
        $items_count = $this->get_node_value('(//span[ @class = "limit_of" ])[2]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            return false;
        }
        $items_count = $this->regex('#(\d+)#', $items_count, 1);
        $this->logg('Items count: '.$items_count);
        $pages_count = ceil($items_count / 18);
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'/'.$page);
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
        foreach ( $this->xpath->query('//div[ contains(@class, "list_product") ]/a[ contains(@href, "/product/") ]') as $node )
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
        $item['product_url'] = $this->baseurl.$url;

        // Brand
        $item['brand'] = $this->site_name;

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Category
        $item['category'] = $this->category;

        // Product type
        $item['product_type'] = $this->get_node_value('//nav[ @class = "breadcrumbs" ]/a[ last()-1 ]');
        if (!isset($item['product_type']))
        {
            $this->logg('No product_type found', 2);
            exit();
        }

        // Price
        $price = $this->get_node_value('//h2[ @itemprop = "price" ]');
        if ($price)
        {
            $price = $this->regex('#([\d\.\,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Discount
        $discount = $this->get_node_value('//p[ @class = "save_price jsSavePrice" ]');
        $item['discount'] = $this->regex('#([\d\.\,]+)#', $discount, 1);
        if (!$item['discount'])
        {
            $item['discount'] = 0;
        }

        // Product options
        $entry_id = $this->get_attribute('//input[ @name = "entry_id" ]', 'value');

        $colors = [];
        foreach ( $this->xpath->query('//div[ contains(@class, "colour_modifier_colours") ]/select/option') as $color )
            $colors[$color->getAttribute('value')] = trim($color->nodeValue);

        $sizes = [];
        foreach ( $this->xpath->query('//div[ contains(@class, "size_modifier_sizes") ]/p') as $size )
            $sizes[$size->getAttribute('data-modifier-id')] = trim($size->nodeValue);

        foreach ($colors as $color_id => $color) {
            foreach ($sizes as $size_id => $size) {
                // Product ID
                $item['product_id'] = $entry_id.'-'.$color_id.'-'.$size_id;

                // Filter out duplicates by ID
                if ( in_array($item['product_id'], $this->product_ids) )
                {
                    $this->logg('Product ID '.$item['product_id']. ' already scraped');
                    return;
                }
                $this->product_ids[] = $item['product_id'];

                // Product option
                $item['product_option'] = $color.' '.$size;

                // Quantity
                $url = $this->baseurl.'/ajax/check-sku-stock-level/'.$entry_id.'/'.$color_id.'/'.$size_id;
                $item['qty_available'] = $this->get_page($url);

                if ($item['qty_available'] != 0)
                    $this->write_item_details($item);
            }
        }
    }

}

$scraper = new SportsmansWarehouseScraper($argv);