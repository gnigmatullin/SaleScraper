<?php
/**
 * @file salomon.php
 * @brief File contains https://salomonsports.co.za/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class SalomonScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Salomon', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'mens-shoes',
            'mens-clothing',
            'mens-bags-packing',
            'womens-shoes',
            'womens-clothing'
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
        $this->category_name = $this->get_node_value('//h1[ @id = "bc-sf-filter-collection-header" ]');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $pages_count = $this->get_node_value('//li[ @class = "pagination__text" ]');
        if (!$pages_count)
        {
            $this->logg('No pages count found');
            $pages_count = 1;
        }
        else
        {
            $pages_count = $this->regex('#(\d+)$#', $pages_count, 1);
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
        foreach ( $this->xpath->query('//div[ @class = "h4 grid-view-item__title product-card__title" ]/a') as $node )
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
        if (!$html) return false;
        $this->logg('Parse item details from '.$url);
        $json = $this->regex('#<script\stype=\"application/json\"\sid=\"ProductJson-product-template-new\">\s+(\{[\s\S]+?\})\s+</script>#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json');
            $this->logg(json_last_error_msg());
            return false;
        }

        $item = array();
        $item['tracking_date'] = date('Y-m-d');

        // Brand
        if (!isset($arr['vendor']))
        {
            $this->logg('No brand found');
            $this->logg('JSON: '. $json);
            return false;
        }
        $item['brand'] = $arr['vendor'];

        // Product name
        if (!isset($arr['title']))
        {
            $this->logg('No product name found');
            $this->logg('JSON: '. $json);
            return false;
        }
        $item['product_name'] = $arr['title'];

        // Category
        $item['category'] = $this->category;

        // Product type
        if (!isset($arr['type']))
        {
            $this->logg('No product type found');
            $item['product_type'] = '';
        }
        else
            $item['product_type'] = $arr['type'];

        // Get all variants
        foreach ($arr['variants'] as $variant)
        {
            // Product ID
            if (!isset($variant['id']))
            {
                $this->logg('No product id found');
                continue;
            }
            $item['product_id'] = $variant['id'];
            $item['product_url'] = $this->baseurl.$url.'?variant='.$variant['id'];

            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID ['.$item['product_id']. '] already scraped');
                continue;
            }
            $this->product_ids[] = $item['product_id'];

            // Price
            if (!isset($variant['price']))
            {
                $this->logg('No price found');
                continue;
            }
            $price = $this->regex('#([\d\,\.]+)#', $variant['price'], 1);
            $item['price'] = str_replace(',', '', $price);
            $item['price'] = preg_replace('#00$#', '', $item['price']);

            // Discount
            $item['discount'] = 0;

            // Product option
            if (!isset($variant['title']))
            {
                $this->logg('No product option found');
                continue;
            }
            $item['product_option'] = $variant['title'];

            // Qty available
            $item['qty_available'] = 0;

            // Write to db
            $this->write_item_details($item);

        }
        return true;
    }

}

$scraper = new SalomonScraper($argv);