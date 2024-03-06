<?php
/**
 * @file gilt.php
 * @brief File contains https://www.gilt.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class GiltScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class GiltScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Gilt', 'USD', $arg);
        $this->parse_catalog();
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog()
    {
        $this->logg('Parse category: '.$this->category);
        $url = $this->baseurl.'/api/v3/catalog/products?page=0&division='.$this->category.'&bindingFilters=division%7Cdepartment%7Cclass%7CboutiqueContextId&pageSize=54';
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 2);
            $this->logg($json);
            return;
        }
        // Get pages count
        if (!isset($arr['meta']['totalPages']))
        {
            $this->logg('No pages count found', 2);
            return;
        }
        $pages_count = $arr['meta']['totalPages'];
        $this->logg('Pages count: '.$pages_count);
        // Parse first page
        $this->logg('Parse catalogue page 0');
        $this->parse_catalog_page($arr);
        
        // Parse rest pages
        for ($page = 1; $page < $pages_count; $page++)
        {
            $json = $this->get_page(str_replace('?page=0', '?page='.$page, $url));
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('Error in json: '.json_last_error(), 2);
                $this->logg($json);
                continue;
            }
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($arr);
            if ($this->debug) break;
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
        foreach ($arr['data'] as $item)
        {
            $this->parse_item($item);
            //if ($this->debug) break;
        }

    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($a)
    {
        $this->logg('Parse item details for productID: '.$a['id']);
        $url = $this->baseurl.'/api/v3/products/'.$a['id'];
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 2);
            $this->logg($json);
            return;
        }
        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $this->baseurl.'/boutique/product/'.$arr['data']['boutiqueId'].'/'.$a['id'];
        
        // Category
        $item['category'] = ucfirst($this->category).' '.$arr['data']['department'];
        if ($item['category'] == '')
        {
            $this->logg('No category found', 1);
            return;
        }
        
        // Product type
        $item['product_type'] = $arr['data']['class'];
        if ($item['product_type'] == '')
        {
            $this->logg('No product type found', 1);
            return;
        }

        // Brand
        $item['brand'] = $arr['data']['brand'];
        if ($item['brand'] == '')
        {
            $this->logg('No brand name found', 1);
            return;
        }
        
        // Product name
        $item['product_name'] = $arr['data']['name'];
        if ($item['product_name'] == '')
        {
            $this->logg('No product name found', 1);
            return;
        }
        
        // Get all variants
        foreach ($arr['data']['skus'] as $variant)
        {
            if ($variant['inventory'] == 0) continue;    // Skip sold
            
            // Product ID
            $item['product_id'] = $variant['id'];
            if ($item['product_id'] == '')
            {
                $this->logg('No product ID found', 1);
                continue;
            }
            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
                continue;
            }
            $this->product_ids[] = $item['product_id'];

            // Price
            $price = $variant['price'] / 100;
            if ($price != '')
            {
                $item['price'] = round($price, 2);
            }
            else
            {
                $this->logg('No price found', 1);
                $item['price'] = 0;
            }
            
            // Original price
            $original_price = $variant['msrp'] / 100;
            if ($original_price != '')
            {
                $item['original_price'] = round($original_price, 2);
                if ($original_price > $item['price'])
                    $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
                else {
                    $this->logg('No discount found', 1);
                    $item['discount'] = 0;
                }
            }
            else
            {
                $this->logg('No discount found', 1);
                $item['discount'] = 0;
            }
            
            // Product option
            $item['product_option'] = $variant['size'];
            if ($item['option'] == '')
            {
                $this->logg('No product option found', 1);
                $item['product_option'] = '';
            }
            
            // Quantity
            $item['qty_available'] = $variant['inventory'];
            if ($item['qty_available'] == '')
            {
                $this->logg('No quantity found', 1);
                $item['qty_available'] = 0;
            }

            if ($item['qty_available'] != 0)
                $this->write_item_details($item);
        }
    }
}

$scraper = new GiltScraper($argv);