<?php
/**
 * @file superbalist.php
 * @brief File contains https://superbalist.com website scraper
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
		$this->set_proxy();
        $this->parse_catalog($this->baseurl.'/browse/?sort_by=newest');
        $this->logg("Scraper stopped");
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

        $this->items_count = $this->get_node_value('//*[ @class = "items-count" ]');
        if (!$this->items_count)
        {
            $this->logg('No items count found', 1);
            $this->items_count = 0;
        }
        if (!preg_match('#[\d,]+#', $this->items_count))
        {
            $this->logg('No items count found', 2);
            $this->logg('HTML: '.$html);
            return false;
        }
        $this->items_count = $this->regex('#([\d,]+)#', $this->items_count, 1);
        $this->logg('Items count: '.$this->items_count);
        $this->write_products_total($this->items_count);

        $pages_count = ceil($this->items_count / 72) + 1;
        $this->logg('Total pages count: '.$pages_count);
        if (isset($this->max_pages)) {
            if ($this->from_page + $this->max_pages < $pages_count)
                $pages_count = $this->from_page + $this->max_pages;
        }
        $this->logg('Pages to scrape: '.$this->max_pages);
        
        for ($page = $this->from_page; $page < $pages_count; $page++)
        {
            $page_html = $this->get_page($this->baseurl.'/browse/?sort_by=newest&page=' . $page);
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
        foreach ( $this->xpath->query('//div[ contains( @class, "bucket-product-with-details" ) ]/a') as $node )
        {
            $link = $node->getAttribute('href');
            if (strpos($link, $this->baseurl) === false) $link = $this->baseurl . $link;
            $this->parse_item($link);
            sleep(3);
            if ($this->debug) break;
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
        $item['brand'] = $this->get_node_value('//a[ contains(@class, "pdp-brand") ]/span');
        if (!$item['brand'])
        {
            $this->logg('No brand name found', 1);
            $item['brand'] = '';
        }
        
        // Category and product type
        $json = $this->regex('#window\.__INITIAL_STATE__=(\{[\s\S]+?\});#', $html, 1);
        $arr = json_decode($json, true);
        if (isset($arr['product'])) {
            $i = 0;
            foreach ($arr['product'] as $product) {
                foreach ($product['breadcrumbs'] as $breadcrumb) {
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
                break;
            }
        }
        else {
            $nodes = $this->xpath->query('//meta[ @property = "product:category" ]');
            foreach ( $nodes as $node )
            {
                $category = $node->getAttribute('content');
                break;
            }
            if ( !isset($category) )
            {
                $this->logg('No category found', 1);
                $item['category'] = '';
                $item['product_type'] = '';
            }
            $item['category'] = $this->regex('#^([\s\S]+)\s-#', $category, 1);
            $item['product_type'] = $this->regex('#-\s([\s\S]+)#', $category, 1);
        }
        
        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "headline-tight" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            $item['product_name'] = '';
        }

        // Product details
        $item['product_details'] = $this->get_node_value('//div[ @class = "product-accordion__title product-details" ]/following-sibling::div');
        if (!$item['product_details'])
        {
            $this->logg('No product details found', 1);
            $item['product_details'] = '';
        }
        $item['product_details'] = $this->filter_text($item['product_details']);

        // Product image 1
        $item['product_image1'] = $this->get_attribute('//img[ @class = "bucket-img" ]', 'src');
        if (!$item['product_image1'])
        {
            $this->logg('No product image found', 1);
            $item['product_image1'] = '';
        }

        // Product colour
        $item['product_colour'] = $this->get_node_value('//div[ ./strong[ contains(., "Colour") ] ]/following-sibling::div');
        if (!$item['product_colour'])
        {
            $this->logg('No product colour found', 1);
            $item['product_colour'] = '';
        }

        // Product tags
        foreach ($this->xpath->query('//div[ @class = "indicator-label" ]/span') as $tag_node) {
            $item['product_tags'] .= trim($tag_node->nodeValue).', ';
        }
        if (isset($item['product_tags']))
            $item['product_tags'] = substr($item['product_tags'], 0, -2);

        // Product ID
        $product_id = $this->regex('#/(\d+)$#', $url, 1);
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
        $json = $this->regex('#superbalist\.appdata\s=\s(\{[\s\S]+?\});#', $html, 1);
        $arr = json_decode($json, true);
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
            $this->logg('No variations found in appdata. Search initial state');
            $json = $this->regex('#window\.__INITIAL_STATE__=(\{[\s\S]+?\});#', $html, 1);
            $arr = json_decode($json, true);
            if (isset($arr['product']))
            {
                foreach ($arr['product'] as $product)
                {
                    if (isset($product['variations']))
                    {
                        foreach ($product['variations'] as $variation_id => $variation)
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
							$item['go_live_date'] = $product['product']['estimated_golive_date'];
							$item['supplier_sku'] = $product['product']['style_code_id'];
							if (isset($product['product']['data']['Style Code'])) {
                                $item['supplier_sku_code'] = $product['product']['data']['Style Code'];
                                $item['supplier_sku_code'] = $this->filter_text($item['supplier_sku_code']);
                            }
                            else {
                                $item['supplier_sku_code'] = '';
                            }
                            $this->write_item_details($item);
                        }
                    }
                    else
                    {
                        $this->logg('No variations found', 1);
                    }
                }
            }
            else
            {
                $this->logg('No variations found', 1);
            }
        }
    }
}

$scraper = new SuperbalistScraper($argv);