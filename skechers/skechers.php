<?php
/**
 * @file dc.php
 * @brief File contains https://www.skechers.com/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class SkechersScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Skechers', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'men/all?genders=M',
            'women/all?genders=W',
        ];
        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/en-us/'.$this->category);
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
        $this->category_name = $this->get_node_value('//h1[ @id = "page-title" ]/span');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $items_count = $this->regex('#Skx\.refinement\.resultCount\s=\s{2}(\d+)#', $html, 1);
        if (!$items_count)
        {
            $this->logg('No items count found');
            $pages_count = 1;
        }
        else
        {
            $items_count = $this->regex('#(\d+)#', $items_count, 1);
            $this->logg('Items count: '.$items_count);
            $pages_count = ceil($items_count / 40);
            $this->logg('Pages count: '.$pages_count);
        }
        
        // Get bookmark
        $bookmark = $this->regex('#Skx\.refinement\.bookmark\s=\s{2}\'([\s\S]+?)\';#', $html, 1);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        
        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $this->logg('Parse catalogue page ' . $page);
            if ($this->category == 'men/all?genders=M')
                $page_html = $this->get_page('https://www.skechers.com/en-us/api/html/products/styles/listing?genders=M&bookmark='.$bookmark);
            else
                $page_html = $this->get_page('https://www.skechers.com/en-us/api/html/products/styles/listing?genders=W&bookmark='.$bookmark);
            //var_dump($page_html);
            $arr = json_decode($page_html, true);
            $bookmark = $arr['bookmark'];
            $page_html = $arr['stylesHtml'];
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
        foreach ( $this->xpath->query('//a[ @class = "prodImg-wrapper js-collectionname-item" ]') as $node )
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
        $item['product_id'] = $this->get_node_value('//span[ @class = "pull-right product-label" ]');
        if (!$item['product_id'])
        {
            $this->logg('No product id found', 2);
            //$this->logg('HTML: '. $html);
            //exit();
            return;
        }
        $item['product_id'] = trim(str_replace('Style #:', '', $item['product_id']));

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $this->get_node_value('//ins[ @class = "price-final" ]');
        if (!$price)
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }
        $price = $this->regex('#([\d\.,]+)#', $price, 1);
        $item['price'] = str_replace(',', '', $price);
        
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
        foreach ( $this->xpath->query('//ul[ @class = "js-product-thumbnails product-color" ]/li/a/img') as $node )
        {
            $item['product_option'] .= $node->getAttribute("alt").' ';
        }
        if (!$item['product_option'])
            $item['product_option'] = '';
        
        // Image URL
        $item['image_url'] = $this->get_attribute('//meta[ @property = "og:image" ]', 'content');
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

$scraper = new SkechersScraper($argv);