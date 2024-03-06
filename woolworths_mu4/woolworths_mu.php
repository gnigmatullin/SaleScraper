<?php
/**
 * @file woolworths.php
 * @brief File contains https://www.woolworths.co.za/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class WoolworthsScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class WoolworthsScraper extends SaleScraper
{

    /**
     * @brief Class WoolworthsScraper
     * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
     */
    public function __construct($arg)
    {
        parent::__construct('Woolworths', 'ZAR', $arg);
		//$this->set_proxy();
        $this->baseurl = 'https://www.woolworths.co.za';

        $lines = file('skus.txt');
        $lines = array_unique($lines);
        
        foreach ($lines as $sku) {
            $this->parse_item(trim($sku));
        }
                
        $this->logg("Scraper stopped");
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($id)
    {
        $url = 'https://woolworths.mu/wp-admin/admin-ajax.php?action=woodmart_ajax_search&number=20&post_type=product&query='.$id;
        $json = $this->get_page($url);
        //var_dump($json);
        
        $arr = json_decode($json, true);
        
        $url = $arr['suggestions'][0]['permalink'];
        if (!$url) {
            $this->logg('No product found');
            return false;
        }
        $html = $this->get_page($url);
        
        if (!$html) return false;
        $this->loadDOM($html);
        
        $this->logg('Parse item details from '.$url);
        
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $url;
        $item['product_id'] = $id;
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped');
            return false;
        }
        $this->product_ids[] = $item['product_id'];
        
        // Brand
        $item['brand'] = '';

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            return false;
        }
        
        // Product description
        $item['product_description'] = $this->get_node_value('//div[ @id = "tab-description" ]');
        if (!$item['product_description'])
        {
            $this->logg('No product_description found', 1);
            $item['product_description'] = '';
        }
        
        // Product category
        $item['category'] = $this->get_node_value('//span[ @class = "posted_in" ]/a');
        if (!$item['category'])
        {
            $this->logg('No category found', 1);
            $item['category'] = '';
        }
        
        // Product price
        $item['price'] = $this->get_node_value('//p[ @class = "price" ]/del/span/bdi');
        if (!$item['price'])
        {
            $item['price'] = 0;
        }
        
        // Product sale price
        $item['sale_price'] = $this->get_node_value('//p[ @class = "price" ]/ins/span/bdi');
        if (!$item['sale_price'])
        {
            $this->logg('No sale_price found', 1);
            $item['sale_price'] = 0;
        }
        
        if ($item['price'] == 0 && $item['sale_price'] == 0)
        {
            $item['price'] = $this->get_node_value('//p[ @class = "price" ]/span/bdi');
            if (!$item['price'])
            {
                $this->logg('No price found', 1);
                $item['price'] = 0;
            }
        }
        $item['price'] = $this->regex('#([\d\.\,]+)#', $item['price']);
        $item['price'] = str_replace(',', '', $item['price']);
        $item['sale_price'] = $this->regex('#([\d\.\,]+)#', $item['sale_price']);
        $item['sale_price'] = str_replace(',', '', $item['sale_price']);
        $item['discount'] = 0;
        
        // Variants
        if (!preg_match('#data-product_variations=\"[\s\S]+?\"#', $html))
        {
            $this->logg('No variants found', 2);
            return;
        }
        $json = $this->regex('#data-product_variations=\"([\s\S]+?)\"#', $html, 1);
        $json = str_replace("&quot;", '"', $json);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            return;
        }
        //var_dump($arr);
        foreach ($arr as $variant) {
            $this->get_variant($item, $variant);
        }

        return true;
    }
    
    /**
     * @brief Get variant details
     * @details Get variant details from array and write to DB
     * @param array $item       [Array of item details]
     * @param array $variant    [Array of variant details]
     */
    private function get_variant($item, $variant)
    {
        // Variant ID
        $item['sku_id'] = $variant['variation_id'];
        if ($item['sku_id'] == '') {
            $this->logg('No variant ID found', 2);
            return;
        }
        
        // Images
        $i = 1;
        foreach ($variant['variation_gallery_images'] as $image) {
            $item['product_image'.$i] = $image['url'];
            $i++;
            if ($i >= 5) break;
        }

        // Product option
        $item['product_option'] = '';
        foreach ($variant['attributes'] as $attribute) {
            $item['product_option'] .= $attribute.' ';
        }

        //var_dump($item);
        // Write to db
        $this->write_item_details($item);
    }
    
}

$scraper = new WoolworthsScraper($argv);