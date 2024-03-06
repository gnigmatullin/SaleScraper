<?php
/**
 * @file sportpursuit.php
 * @brief File contains https://www.sportpursuit.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class SportPurSuitScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class SportPurSuitScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('SportPurSuit', 'GBP', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    public function parse_categories()
    {
        // Get category id
        switch ($this->category)
        {
            case 'clothing': $this->category_id = 3394; break;
            case 'footwear': $this->category_id = 3396; break;
            default:
                $this->logg('Unknown category: '.$this->category, 2);
                return;
        }
        $this->parse_catalog();
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @return bool
     */
    private function parse_catalog()
    {
        $this->logg('Parse category: '.$this->category);
        $url = 'https://raven.sportpursuit.com/api/products/get?limit=42&p=1&store_id=1&sale_context=0&category='.$this->category_id.'&order=gender&dir=male_first';
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error(), 2);
            $this->logg($json);
            return;
        }
        // Get pages count
        if (!isset($arr['page']['last']))
        {
            $this->logg('No page count found', 2);
            return;
        }
        $pages_count = $arr['page']['last'];
        $this->logg('Pages count: '.$pages_count);
        // Parse first page
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($arr);
        
        // Parse rest pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $json = $this->get_page(str_replace('&p=1', '&p='.$page, $url));
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('Error in JSON: '.json_last_error(), 1);
                $this->logg($json);
                continue;
            }
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($arr);
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($arr)
    {
        // Get all items
        foreach ($arr['products'] as $item)
            $this->parse_item($item);
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($a)
    {
        $this->logg('Parse item details for productID: '.$a['entity_id']);
        $url = 'https://raven.sportpursuit.com/api/v2.0.0/products/'.$a['entity_id'].'/?shipment=ZA&store_id=1';
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error(), 2);
            $this->logg($json);
            return;
        }
        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $this->baseurl.'/catalog/product/view/id/'.$arr['productID'];
        $item['category'] = $this->category;

        // Product type
        $item['product_type'] = $a['product_category'];
        if ($item['product_type'] == '')
        {
            $this->logg('No product type found', 1);
            $item['product_type'] = '';
        }
        // Brand
        $item['brand'] = $arr['brand']['name'];
        if ($item['brand'] == '')
        {
            $this->logg('No brand name found', 2);
            $this->logg(json_encode($arr));
            return;
        }
        // Product name
        $item['product_name'] = $arr['name'];
        if ($item['product_name'] == '')
        {
            $this->logg('No product name found', 2);
            $this->logg(json_encode($arr));
            return;
        }
        // Product ID
        $item['product_id'] = $arr['productID'];
        if ($item['product_id'] == '')
        {
            $this->logg('No product ID', 2);
            $this->logg(json_encode($arr));
            return;
        }
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
            return;
        }
        $this->product_ids[] = $item['product_id'];
        // Price
        $price = $arr['prices']['current'];
        if ($price != '')
        {
            $price = $this->regex('#([\d\,\.]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }
        // Discount
        $item['discount'] = $arr['discount'];
        if ($item['discount'] == '')
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Quantity
        $item['qty_available'] = 0;

        // Get all sizes
        foreach ($arr['options'] as $option)
        {
            if (strpos($option['code'], 'size') === false) continue;
            foreach ($option['values'] as $value)
            {
                // Product option
                $item['product_option'] = $value['value'];
                $this->write_item_details($item);
            }
        }
        // No size found write with no size
        if (!isset($item['product_option']))
        {
            $this->logg('No size found', 1);
            $item['product_option'] = '';
            $this->write_item_details($item);
        }
    }
}

$scraper = new SportPurSuitScraper($argv);