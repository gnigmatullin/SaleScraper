<?php
/**
 * @file dc.php
 * @brief File contains https://www.archivestore.co.za/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class ArchivestoreScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Archivestore', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'footwear' => 'ro3ij4',
            'clothing' => '1tvypy',
        ];
        $this->apiurl = 'https://www.archivestore.co.za/search/ajaxResultsList.jsp?N={id}&Ns=availability|1||daysAvailable|1||sortPriority|0&Nrpp=15&page=1&No=0&baseState={id}';
        // Get all categories
        foreach ($categories as $category_name => $category_id) {
            $this->category = $category_name;
            $this->logg('Category: '.$this->category);
            $this->apiurl = str_replace('{id}', $category_id, $this->apiurl);
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
        $this->logg('Parse catalogue '.$url);
        $arr = json_decode($html, true);

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
        $pages_count = $arr['data']['totalPages'];
        if (!$pages_count)
        {
            $this->logg('No pages count found');
            $pages_count = 1;
        }
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($arr);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $no = ($page - 1) * 15;
            $page_url = str_replace('page=1', 'page='.$page, $url);
            $page_url = str_replace('No=0', 'No='.$no, $url);
            $html = $this->get_page($page_url);
            $this->logg('Parse catalogue page ' . $page);
            $arr = json_decode($html, true);
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
        foreach ($arr['data']['products'] as $object)
        {
            $this->parse_item($object);
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
    private function parse_item($object)
    {
        $this->logg('Parse item details');

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $this->baseurl.$object['pdpLinkUrl'];

        // Brand
        $item['brand'] = $this->site_name;
        
        // Product id
        $item['product_id'] = $object['id'];
        if (!$item['product_id'])
        {
            $this->logg('No product id found', 2);
            //$this->logg('HTML: '. $html);
            //exit();
            return;
        }

        // Product name
        $item['product_name'] = $object['name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $object['latestPriceRange'];
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
        $item['product_option'] = '';
        
        // Image URL
        $item['image_url'] = $object['defaultImages'][0];
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

$scraper = new ArchivestoreScraper($argv);