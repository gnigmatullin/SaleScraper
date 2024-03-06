<?php
/**
 * @file stevemadden.php
 * @brief File contains https://stevemadden.co.za/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class StevemaddenScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Stevemadden', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'womens',
            'mens',
            'accessories',
            'kids',
            'sale-all-products'
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
        $this->category_name = $this->category;

        // Get pages count
        $items_count = $this->get_node_value('//div[ contains(@class, "totalResults") ]/div/span');
        if (!$items_count)
        {
            $this->logg('No items count found');
            $pages_count = 1;
        }
        else
        {
            $items_count = $this->regex('#(\d+)#', $items_count, 1);
            $this->logg('Items count: '.$items_count);
            $pages_count = ceil($items_count / 72);
            $this->logg('Pages count: '.$pages_count);
        }
        
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
        foreach ( $this->xpath->query('//div[ @class = "details" ]/a') as $node )
        {
            $link = $node->getAttribute("href");
            //$this->logg($link);
            if ($link != '') $this->parse_item($link);
            if ($this->debug) break;
            sleep(2);
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
        if (strpos($this->category, 'call-it-spring') === false)
            $item['brand'] = $this->site_name;
        else
            $item['brand'] = 'Call It Spring';

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = '';

        // Price
        $price = $this->get_node_value('//h3[ @class = "price" ]');
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
        if ($original_price = $this->get_node_value('//h3[ @class = "price" ]/del')) {
            $original_price = $this->regex('#([\d\.,]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
            $item['discount'] = 0;

        // Get sizes
        foreach ( $this->xpath->query('//div[ @class = "swatch clearfix select_size" ]/div[ contains(@class, "available") ]') as $node )
        {
            // Product ID
            foreach ($this->xpath->query('./input', $node) as $col )
            {
                $item['product_id'] = $col->getAttribute('id');
                $item['product_option'] = $col->getAttribute('value');
                break;
            }

            // Qty available
            $item['qty_available'] = 0;

            // Write to db
            $this->write_item_details($item);
        }

        return true;
    }

}

$scraper = new StevemaddenScraper($argv);