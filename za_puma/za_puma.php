<?php
/**
 * @file dc.php
 * @brief File contains https://za.puma.com/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class PumaScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Puma', 'ZAR', $arg);
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
        ];
        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category.'.html');
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
        $this->category_name = $this->get_node_value('//h1[ @class = "page-title" ]');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $items_count = $this->get_node_value('//p[ @class = "page-products-count" ]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            $pages_count = 1;
        }
        else
        {
            $items_count = $this->regex('#(\d+)#', $items_count, 1);
            $this->logg('Items count: '.$items_count);
            $pages_count = ceil($items_count / 36);
            $this->logg('Pages count: '.$pages_count);
        }
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'?p='.$page);
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
        foreach ( $this->xpath->query('//a[ @class = "product-item__name" ]') as $node )
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
        $item['product_url'] = $url;

        // Brand
        $item['brand'] = $this->site_name;
        
        // Product id
        $item['product_id'] = $this->get_node_value('//span[ @class = "product-article__value js-color-article" ]');
        if (!$item['product_id'])
        {
            $this->logg('No product id found', 2);
            //$this->logg('HTML: '. $html);
            //exit();
            return;
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "page-title" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $this->get_node_value('//span[ @class = "special-price" ]//span[ @class = "price"]');
        if ($price) // discount price
        {
            $price = $this->regex('#([\d\.,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
            $original_price = $this->get_node_value('//span[ @class = "old-price sly-old-price no-display" ]//span[ @class = "price" ]');
            if ($original_price)
            {
                $original_price = $this->regex('#([\d\.,]+)#', $original_price, 1);
                $item['original_price'] = str_replace(',', '', $original_price);
                $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
            }
            else
            {
                // Discount
                $item['discount'] = 0;
            }
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
            $item['discount'] = 0;
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
        
        // Product option
        $item['product_option'] = $this->get_node_value('//span[ @id = "selected-color-value" ]');
        
        // Image URL
        $item['image_url'] = $this->get_attribute('//img[ @class = "gallery-item__img" ]', 'src');
        if (!$item['image_url'])
        {
            $this->logg('No image URL found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Write to DB
        $this->write_item_details($item);
    }

}

$scraper = new PumaScraper($argv);