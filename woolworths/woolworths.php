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
        
        $this->parse_catalog('https://www.woolworths.co.za/cat/Women/Clothing/_/N-1z13s4r');
        $this->parse_catalog('https://www.woolworths.co.za/cat/Women/Shoes/_/N-1z13s3v');
        $this->parse_catalog('https://www.woolworths.co.za/cat/Women/Accessories/_/N-1z13s44');
        
        //$this->parse_item('https://www.woolworths.co.za/prod/Women/Clothing/Dresses-Jumpsuits/Dresses/Woolworths-Print-Belted-Wrap-Midi-Dress/_/A-505774124?isFromPLP=true');        
        //$this->parse_item('https://www.woolworths.co.za/prod/Black-Friday/30-off-Selected-Fashion-Footwear/Men/Shoes/Sneakers/RE-Plain-High-Top-Sneakers/_/A-505173666?isFromPLP=true');
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

        $this->items_count = $this->regex('#\"totalNumRecs\"\:(\d+)#', $html, 1);
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
        //$this->write_products_total($this->items_count);

        $pages_count = ceil($this->items_count / 60) + 1;
        $this->logg('Total pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $offset = ($page - 1) * 60;
            $page_html = $this->get_page($url.'?No='.$offset.'&Nrpp=60');
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
        foreach ( $this->xpath->query('//a[ @class = "range--title" ]') as $node )
        {
            $link = $node->getAttribute('href');
            if (strpos($link, $this->baseurl) === false) $link = $this->baseurl . $link;
            $this->parse_item($link);
            //sleep(3);
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
        if (!$html) return false;
        $this->loadDOM($html);
        //var_dump($html);
        $this->logg('Parse item details from '.$url);
        $json = $this->regex('#__INITIAL_STATE__\s=\s(\{[\s\S]+\})</script>#', $html, 1);
        //var_dump($json);
        
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json');
            $this->logg(json_last_error_msg());
            return false;
        }
        
        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $url;
        
        // Brand
        $item['brand'] = $this->get_node_value('//span[ @class = "pdp-new-font text-caps font-graphic" ]');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 2);
            return;
        }
        
        // Product name
        if (!isset($arr['pdp']['productInfo']['displayName']))
        {
            $this->logg('No product name found');
            return false;
        }
        $item['product_name'] = $arr['pdp']['productInfo']['displayName'];
        
        // Product description
        if (!isset($arr['pdp']['productInfo']['longDescription']))
        {
            $this->logg('No product description found');
            $item['product_description'] = '';
        }
        else {
            $item['product_description'] = strip_tags($arr['pdp']['productInfo']['longDescription']);
        }
        
        // Product department
        if (!isset($arr['pdp']['productInfo']['breadcrumbs']['default'][0]['label']))
        {
            $this->logg('No product department found');
            $item['product_department'] = '';
        }
        else {
            $item['product_department'] = $arr['pdp']['productInfo']['breadcrumbs']['default'][0]['label'];
        }
  
        // Product type
        if (!isset($arr['pdp']['productInfo']['breadcrumbs']['default'][1]['label']))
        {
            $this->logg('No product type found');
            $item['product_type'] = '';
        }
        else {
            $item['product_type'] = $arr['pdp']['productInfo']['breadcrumbs']['default'][1]['label'];
        }
        $item['category'] = $item['product_type'];
        
        // Product subcategory
        if (!isset($arr['pdp']['productInfo']['breadcrumbs']['default'][2]['label']))
        {
            $this->logg('No product subcategory found');
            $item['product_subcategory'] = '';
        }
        else {
            $item['product_subcategory'] = $arr['pdp']['productInfo']['breadcrumbs']['default'][2]['label'];
        }

        // Product ID
        if (!isset($arr['pdp']['productInfo']['productId']))
        {
            $this->logg('No product name found');
            return false;
        }
        $item['product_id'] = $arr['pdp']['productInfo']['productId'];
        
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped');
            return false;
        }
        $this->product_ids[] = $item['product_id'];
        
        // Product image 1
        if (!isset($arr['pdp']['productInfo']['colourSKUs'][0]['images'][0]['external']))
        {
            $this->logg('No image 1 found');
            $item['product_image1'] = '';
        }
        else {
            $item['product_image1'] = $arr['pdp']['productInfo']['colourSKUs'][0]['images'][0]['external'];        
        }
        
        // Product image 2
        if (!isset($arr['pdp']['productInfo']['colourSKUs'][0]['images'][1]['external']))
        {
            $this->logg('No image 2 found');
            $item['product_image2'] = '';
        }
        else {
            $item['product_image2'] = $arr['pdp']['productInfo']['colourSKUs'][0]['images'][1]['external'];
        }
        
        // Product image 3
        if (!isset($arr['pdp']['productInfo']['colourSKUs'][0]['images'][2]['external']))
        {
            $this->logg('No image 3 found');
            $item['product_image3'] = '';
        }
        else {
            $item['product_image3'] = $arr['pdp']['productInfo']['colourSKUs'][0]['images'][2]['external'];
        }
        
        // Product image 4
        if (!isset($arr['pdp']['productInfo']['colourSKUs'][0]['images'][3]['external']))
        {
            $this->logg('No image 4 found');
            $item['product_image4'] = '';
        }
        else {
            $item['product_image4'] = $arr['pdp']['productInfo']['colourSKUs'][0]['images'][3]['external'];
        }
        
        if (!isset($arr['pdp']['productInfo']['colourSKUs'][0]['id']))
        {
            $this->logg('No sku id found');
            $item['sku_id'] = '';
        }
        else {
            $item['sku_id'] = $arr['pdp']['productInfo']['colourSKUs'][0]['id'];
        }
        
        // Product option
        $item['product_option'] = '';
        foreach ($arr['pdp']['productInfo']['styleIdSizeSKUsMap'] as $style) {
            foreach ($style as $size) {
                $item['product_option'] .= $size['colour'].' '.$size['size'].' | ';
            }
        }

        $item['sale_price'] = 0;
        $item['price'] = 0;
        foreach ($arr['pdp']['productPrices'] as $price) {
            foreach ($price as $plist) {
                $item['sale_price'] = $plist['skuPrices'][$item['sku_id']]['SalePrice'];
                $item['price'] = $plist['skuPrices'][$item['sku_id']]['ListPrice'];
            }
        }
        
        if ($item['sale_price'] == '')
            $item['sale_price'] = 0;
        if ($item['price'] == '')
            $item['price'] = 0;
        
        $item['discount'] = 0;
        
        // Write to db
        $this->write_item_details($item);

        return true;
    }
    
}

$scraper = new WoolworthsScraper($argv);