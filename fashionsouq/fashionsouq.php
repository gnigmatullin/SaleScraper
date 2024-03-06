<?php
/**
 * @file fashionsouq.php
 * @brief File contains https://fashion.souq.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class FashionSouqScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class FashionSouqScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('FashionSouq', 'AED', $arg);
        $this->set_cookies($this->category);

        // Get category name from code
        $category = $this->category;
        switch ($this->category)
        {
            case 4096: $this->category = 'Woman'; break;
            case 4098: $this->category = 'Man'; break;
            case 3810: $this->category = 'Kids'; break;
            default:
                $this->logg('Unknown category: '.$this->category);
                exit();
        }
        $this->logg('Category: '.$this->category);
        $this->parse_catalog($this->baseurl.'/ae-en/search?campaign_id='.$category.'&page=1&sort=best');
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
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json');
            $this->logg(json_last_error_msg());
            $this->logg($json);
            return false;
        }
        if (!isset($arr['metadata']['total_pages']))
        {
            $this->logg('No pages count found');
            $pages_count = 1;
        }
        else
            $pages_count = $arr['metadata']['total_pages'];
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        if (!isset($arr['data']))
        {
            $this->logg('No data found in json');
            $this->logg($json);
        }
        else
            $this->parse_catalog_page($arr['data']);

        for ($page = 2; $page <= $pages_count; $page++)
        {
            $json = $this->get_page(str_replace('&page=1', '&page='.$page, $url));
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('Error in json', 1);
                $this->logg(json_last_error_msg());
                $this->logg($json);
                return false;
            }
            if (!isset($arr['data']))
            {
                $this->logg('No data found in json', 1);
                $this->logg($json);
                continue;
            }
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($arr['data']);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($data)
    {
        foreach ( $data as $item )
        {
            $this->parse_item($item);
            //if ($this->>debug) break;
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($arr)
    {
        $this->logg('Parse item details');

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $arr['url'];
        
        // Brand
        $item['brand'] = $arr['manufacturer_en'];
        if (!$item['brand'])
        {
            $this->logg('No brand found', 1);
            $this->logg(json_encode($arr));
            return false;
        }
        
        // Product name
        $item['product_name'] = $arr['title'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            $this->logg(json_encode($arr));
            return false;
        }

        // Category
        $item['category'] = $this->category;

        // Price
        $price = $arr['price']['price'];
        if ($price)
        {
            $price = $this->regex('#([\d,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Discount
        if (isset($arr['price_saving_percentage']))
            $item['discount'] = $arr['price_saving_percentage'];
        else
            $item['discount'] = 0;

        // Product type
        $item['product_type'] = $arr['item_type_en'];
        if (!$item['product_type'])
        {
            $this->logg('No product type found', 1);
            $this->logg(json_encode($arr));
            return false;
        }

        // Product ID
        $item['product_id'] = $arr['unit_id'];
        if (!$item['product_id'])
        {
            $this->logg('No product ID found', 1);
            $this->logg(json_encode($arr));
            return false;
        }
        
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
            return false;
        }
        $this->product_ids[] = $item['product_id'];

        // Quantity
        $item['qty_available'] = $arr['available_quantity'];
        if (!$item['qty_available'])
        {
            $this->logg('No quantity found', 1);
            $this->logg(json_encode($arr));
            return false;
        }
        
        // Product option
        foreach ( $arr['connections']['size'] as $size => $value )
        {
            if ($value['unit_id'] == $item['product_id'])
                $item['product_option'] = $size;
        }
        if (!isset($item['product_option']))
        {
            //$this->logg('No item size found', 1);
            $item['product_option'] = '';
        }

        if ($item['qty_available'] != 0)
            $this->write_item_details($item);
        
        // Check for multiple sizes
        $product_id = $item['product_id'];
        if ( count($arr['connections']['size']) > 1 )
        {
            foreach ( $arr['connections']['size'] as $size => $value )
            {
                if ($value['unit_id'] == $product_id) continue;
                $this->logg('Get quantity for size: '.$size.' unit ID: '.$value['unit_id']);
                $url = str_replace($item['product_id'], $value['unit_id'], $arr['url']);
                $qty = $this->get_quantity($url);
                if (!$qty) continue;
                $this->logg('Quantity: '.$qty);
                $item['product_option'] = $size;
                $item['qty_available'] = $qty;
                $item['product_url'] = $url;
                $item['product_id'] = $value['unit_id'];

                if ($item['qty_available'] != 0)
                    $this->write_item_details($item);
            }
        }
        
    }

    /**
     * @brief Get product quantity from JSON
     * @param string $url   [Page URL]
     * @return bool|int
     */
    private function get_quantity($url)
    {
        $html = $this->get_page($url);
        $json = $this->regex('#var\sglobalBucket\s=(\{[\s\S]+?\})\s+<\/script>#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json');
            $this->logg(json_last_error_msg());
            $this->logg($json);
            return false;
        }
        if (!isset($arr['Page_Data']['product']['quantity']))
        {
            $this->logg('No quantity found');
            $this->logg($json);
            return false;
        }
        else return $arr['Page_Data']['product']['quantity'];
    }
}

$scraper = new FashionSouqScraper($argv);