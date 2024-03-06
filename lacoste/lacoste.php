<?php
/**
 * @file lacoste.php
 * @brief File contains https://global.lacoste.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class LacosteScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Lacoste', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'men/clothing',
            'men/shoes',
            'men/accessories',
            'men/leather-goods',
            'women/clothing',
            'women/shoes',
            'women/leather-goods',
            'women/accessories'
        ];
        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/en/lacoste/'.$this->category);
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
        $this->category_name = $this->get_node_value('//h1[ @class = "title--xlarge l-vmargin--medium" ]');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $pages_count = $this->get_node_value('//div[ @class = "pagination ff-normal fs--medium l-vmargin--xlarge" ]/a[ last()-1 ]/text()');
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
        foreach ( $this->xpath->query('//a[ @class = "simple-link js-product-tile-link" ]') as $node )
        {
            $link = $node->getAttribute("href");
            //$this->logg($link);
            if ($link != '') $this->parse_item($link);
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
        
        // Product id
        if (!preg_match('#id:\s\'.+?\'#', $html))
        {
            $this->logg('No product id found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $item['product_id'] = $this->regex('#id:\s\'(.+?)\'#', $html, 1);

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "title--medium l-vmargin--medium padding-m-2" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $item['price'] = 0;

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
        
        // Product option
        $item['product_option'] = trim($this->get_node_value('//p[ @class = "js-pdp-color-title pdp-selected-color fs--small l-vmargin--small l-hmargin--small" ]'));
        
        // Image URL
        $item['image_url'] = $this->get_attribute('//img[ @class = "js-zoomable cursor-zoom-in img-fill-width" ]', 'data-zoom-url');
        if (!$item['image_url'])
        {
            $this->logg('No image URL found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $item['image_url'] = 'https:'.$item['image_url'];
        
        // Write to DB
        $this->write_item_details($item);
    }

}

$scraper = new LacosteScraper($argv);