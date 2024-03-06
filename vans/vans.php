<?php
/**
 * @file dc.php
 * @brief File contains https://www.vans.com/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class VansScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Vans', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            //'mens-shoes' => 1091108,
            'mens-clothes' => 1091109,
            //'womens-shoes' => 1091111,
            //'womens-clothes' => 1091112
        ];
        // Get all categories
        foreach ($categories as $category_name => $category_id) {
            $this->category = $category_name;
            $this->logg('Category: '.$this->category);
            $this->category_id = $category_id;
            $this->apiurl = 'https://www.vans.com/shop/VFAjaxGetFilteredSearchResultsView?categoryId='.$this->category_id.'&searchSource=N&storeId=10153&catalogId=10703&langId=-1&beginIndex=0&returnProductsOnly=true&requesttype=ajax';
            $this->parse_catalog($this->apiurl);
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
        $html = str_replace(['\n', '\t'], '', $html);
        $html = str_replace('\"', '"', $html);
        $html = $this->regex('#\"products\":\"([\s\S]+)\"#', $html, 1);
        //var_dump($html);
        
        $this->logg('Parse catalogue '.$url);
        $this->loadDOM($html);

        // Category name
        unset($this->category_name);
        $this->category_name = $this->category;
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $items_count = $this->get_attribute('//input[ @class = "total-count-js" ]', 'value');
        if (!$items_count)
        {
            $this->logg('No items count found');
            $pages_count = 1;
        }
        else
        {
            $items_count = $this->regex('#(\d+)#', $items_count, 1);
            $this->logg('Items count: '.$items_count);
            $pages_count = ceil($items_count / 24);
            $this->logg('Pages count: '.$pages_count);
        }
        
        $this->logg('Parse catalogue page 1');
        //$this->parse_catalog_page($html);

        // Get pages
        for ($page = 18; $page <= $pages_count; $page++)
        {
            $index = ($page-1) * 24;
            $page_html = $this->get_page(str_replace('beginIndex=0', 'beginIndex='.$index, $this->apiurl));
            $page_html = str_replace(['\n', '\t'], '', $page_html);
            $page_html = str_replace('\"', '"', $page_html);
            $page_html = $this->regex('#\"products\":\"([\s\S]+)\"#', $page_html, 1);
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
        preg_match_all('#<a\sclass=\"product-block-name-link"\stitle=\".+?\"\shref=\"(.+?)\">#', $html, $matches);
        foreach ($matches[1] as $link)
        {
            $this->logg($link);
            $link = 'https://www.vans.com/customs-designs.era-classic.4223dd18d10883b124f052fdedf85c23.html?style=CST815';
            if ($link != '') $this->parse_item($link);
            sleep(2);
            //if ($this->debug) break;
            exit();
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
        $item['product_id'] = $this->get_attribute('//meta[ @itemprop = "productID" ]', 'content');
        if (!$item['product_id'])
        {
            $this->logg('No product id found', 2);
            $item['product_id'] = '';
            $this->logg('HTML: '. $html);
            exit();
            //return;
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "product-info-js" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $this->get_attribute('//meta[ @itemprop = "price" ]', 'content');
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
        
        // Product option
        $item['product_option'] = $this->get_node_value('//span[ @class = "product-content-form-attr-selected attr-selected attribute-label-value attribute-label-value-js attr-selected-color-js" ]');
        if (!$item['product_option'])
            $item['product_option'] = '';
        
        // Image URL
        $item['image_url'] = $this->get_attribute('//meta[ @itemprop = "image" ]', 'content');
        if (!$item['image_url'])
        {
            $item['image_url'] = $this->get_attribute('//img[ @class = "hero-image" ]', 'src');
            if (!$item['image_url'])
            {
                $this->logg('No image URL found', 2);
                $this->logg('HTML: '. $html);
                exit();
            }
        }

        // Write to DB
        $this->write_item_details($item);
    }

}

$scraper = new VansScraper($argv);