<?php
/**
 * @file superbalist_api.php
 * @brief File contains https://superbalist.com website scraper (with API usage)
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class SuperbalistScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class SuperbalistScraper extends SaleScraper
{

    /**
     * @brief Class SuperbalistScraper
     * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
     */
    public function __construct($arg)
    {
        parent::__construct('Superbalist', 'ZAR', $arg);
		//$this->set_proxy();
        $this->parse_catalog($this->baseurl.'/api/public/catalogue?sort_by=newest&page=1');
        $this->logg("Scraper stopped");
    }

    function curl($url)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Mobile Safari/537.36',
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_RETURNTRANSFER => 1,
            //CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_URL => $url,
            CURLOPT_PROXY => 'nl.proxymesh.com:31280',
            CURLOPT_PROXYUSERPWD => $this->proxy_userpwd,
        );
        $this->logg("Get page $url");
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $start = microtime(true);
        $html = curl_exec($ch);
        $finish = microtime(true) - $start;
        $this->logg("Load time: $finish");
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
        return $html;
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

        $this->items_count = $arr['search']['total'];
        if (!$this->items_count)
        {
            $this->logg('No items count found', 1);
            $this->items_count = 0;
        }
        if (!preg_match('#[\d,]+#', $this->items_count))
        {
            $this->logg('No items count found', 2);
            return false;
        }
        $this->items_count = $this->regex('#([\d,]+)#', $this->items_count, 1);
        $this->logg('Items count: '.$this->items_count);
        $this->write_products_total($this->items_count);

        $pages_count = $arr['search']['last_page'];
        $this->logg('Total pages count: '.$pages_count);
        if (isset($this->max_pages)) {
            if ($this->from_page + $this->max_pages < $pages_count)
                $pages_count = $this->from_page + $this->max_pages;
        }
        $this->logg('Pages to scrape: '.$this->max_pages);

        // Collect all product IDs to scrape
        $this->ids_to_scrape = [];
        for ($page = $this->from_page; $page < $pages_count; $page++)
        {
            $page_json = $this->get_page($this->baseurl.'/api/public/catalogue?sort_by=newest&page=' . $page);
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($page_json);
            if ($this->debug) break;
        }

        $this->logg('Products to scrape: '.count($this->ids_to_scrape));
        
        $unique_ids = array_unique($this->ids_to_scrape);
        
        $this->logg('Unique products to scrape: '.count($unique_ids));

        // Parse products
        foreach ($this->ids_to_scrape as $id) {
            $this->parse_item($id);
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog
     * @param string $json  [Page HTML]
     */
    private function parse_catalog_page($json)
    {
        $arr = json_decode($json, true);

        foreach ($arr['search']['data'] as $item)
        {
            $this->ids_to_scrape[] = $item['id'];
            if ($this->debug) break;
        }
    }

    /**
     * @brief Parse item
     * @details Parse item details
     * @param int $id   [Item ID]
     * @return bool
     */
    private function parse_item($id)
    {
        $json = $this->get_page('https://superbalist.com/api/public/products/'.$id);
        $this->logg('Parse item details '.$id);
        $arr = json_decode($json, true);
        
        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $arr['og_data']['og:url'];
        
        // Brand
        $item['brand'] = $arr['og_data']['og:brand'];
        if (!$item['brand'])
        {
            $this->logg('No brand name found', 1);
            $item['brand'] = '';
        }
        
        // Category and product type
        $i = 0;
        foreach ($arr['breadcrumbs'] as $breadcrumb) {
            switch ($i) {
                case 0:
                    $item['category'] = $breadcrumb['title'];
                    break;
                case 1:
                    $item['product_type'] = $breadcrumb['title'];
                    break;
                case 2:
                    $item['product_subtype'] = $breadcrumb['title'];
                    break;
            }
            $i++;
        }
        if (!$item['category']) {
            $this->logg('No category found', 1);
            $item['category'] = '';
        }
        if (!$item['product_type']) {
            $this->logg('No product_type found', 1);
            $item['product_type'] = '';
        }
        if (!$item['category']) {
            $this->logg('No product_subtype found', 1);
            $item['product_subtype'] = '';
        }

        // Product name
        $item['product_name'] = $arr['og_data']['og:title'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            $item['product_name'] = '';
        }

        // Product details
        $item['product_details'] = trim($arr['product']['text_description'].' '.
            $arr['product']['data']['Colour'].' '.
            $arr['product']['data']['Brand'].' '.
            $arr['product']['data']['Material']);
        if (!$item['product_details'])
        {
            $this->logg('No product details found', 1);
            $item['product_details'] = '';
        }
        $item['product_details'] = $this->filter_text($item['product_details']);

        // Product image 1
        $item['product_image1'] = $arr['og_data']['og:image'];
        if (!$item['product_image1'])
        {
            $this->logg('No product image found', 1);
            $item['product_image1'] = '';
        }

        // Product colour
        $item['product_colour'] = $arr['product']['data']['Colour'];
        if (!$item['product_colour'])
        {
            $this->logg('No product colour found', 1);
            $item['product_colour'] = '';
        }

        // Product tags
        $item['product_tags'] = '';

        // Product ID
        $product_id = $id;
        if (!$product_id)
        {
            $this->logg('No product ID found', 2);
            return;
        }
        
        // Filter out duplicates by ID
        if ( in_array($product_id, $this->product_ids) )
        {
            $this->logg('Product ID '.$product_id. ' already scraped', 1);
            return;
        }
        $this->product_ids[] = $item['product_id'];
        
        // Get all product variations
        if (isset($arr['page_impression']['metadata']['variations']))
        {
            foreach ($arr['page_impression']['metadata']['variations'] as $variation_id => $variation)
            {
                if (isset($variation['fields']['Size'])) {
                    $item['product_option'] = $variation['fields']['Size'];
                    $item['product_size'] = $variation['fields']['Size'];
                }
                else if ( isset($variation['fields']['Type']) )
                    $item['product_option'] = $variation['fields']['Type'];
                else
                {
                    $this->logg('No field size or type found', 1);
                    $item['product_option'] = '';
                }
                $item['price'] = $variation['reduced_price'];
                $item['discount'] = $variation['reduced_percentage'];
                $item['qty_available'] = $variation['quantity'];
                $item['product_id'] = $product_id.'_'.$variation_id;
                $this->write_item_details($item);
            }
            return true;
        }
        else
        {
            $this->logg('No product variations found', 1);
            return;
        }
    }
}

$scraper = new SuperbalistScraper($argv);