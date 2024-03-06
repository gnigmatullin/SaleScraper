<?php
/**
 * @file forevernew.php
 * @brief File contains https://www.forevernew.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class ForeverNewScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class ForeverNewScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('ForeverNew', 'ZAR', $arg);
        $this->parse_catalog($this->baseurl.'/index.php/'.$this->category.'?p=1&ajax=1');
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
        if (!isset($arr['product_list']))
        {
            $this->log('Product list not found', 2);
            $this->logg('HTML: '.$html);
            return false;
        }
        $products_count = $this->regex('#(\d+)\sproducts#', $arr['product_list'], 1);
        if (!$products_count)
        {
            $this->logg('No products count found', 2);
            $this->logg('HTML: '.$html);
            return false;
        }
        $pages_count = ceil($products_count / 12);
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse item links from catalogue page: 1');
        $this->parse_catalog_page($html);
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_url = str_replace('?p=1', '?p='.$page, $url);
            $html = $this->get_page($page_url);
            $this->logg('Parse item links from catalogue page: '.$page);
            $this->parse_catalog_page($html);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($html)
    {
        $arr = json_decode($html, true);
        if (!isset($arr['product_list']))
        {
            $this->log('Product list not found', 2);
            $this->logg('HTML: '.$html);
            return false;
        }
        $this->loadDOM($arr['product_list']);
        foreach ( $this->xpath->query('//a[ @class = "product-image" ]') as $node )
        {
            $link = $node->getAttribute("href");
            $this->parse_item($link);
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
        $item['product_url'] = $url;
        $item['brand'] = 'Forever New';
        
        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @class = "product-name" ]/span');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            $this->logg('HTML: '. $html);
            return false;
        }
        
        // Product ID
        $item['product_id'] = $this->get_attribute('//input[ @name = "product" ]', 'value');
        if (!$item['product_id'])
        {
            $this->logg('No product ID', 1);
            $this->logg('HTML: '. $html);
            return false;
        }
        
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
            return false;
        }
        $this->product_ids[] = $item['product_id'];
        
        // Price
        $price = $this->get_node_value('//span[ @class = "price" ]');
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
        
        // Special price
        $special_price = $this->get_node_value('//p[ @class = "special-price" ]/span[ @class = "price" ]');
        if ($special_price)
        {
            $special_price = $this->regex('#([\d,]+)#', $special_price, 1);
            $item['special_price'] = str_replace(',', '', $special_price);
            // Discount
            $item['discount'] = round( ($item['price'] - $item['special_price']) / $item['price'] * 100 );
        }
        else
        {
            $this->logg('No special price found', 1);
            $item['special_price'] = 0;
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->get_node_value('//div[ @class = "breadcrumbs" ]/ul/li[ contains( @class, "category" ) ]/a');
        if (!$item['category'])
        {
            $this->logg('No category found', 1);
            $item['category'] = '';
        }
        
        // Product type
        $item['product_type'] = '';
        
        // Get options
        $json = $this->regex('#var\sspConfig\s=\snew\sProduct\.Config\(({[\s\S]+?})\)#', $html, 1);
        if ($json)
        {
            $this->logg('Search for spconfig');
            $spconfig = json_decode($json, true);
            if (!isset($spconfig['attributes']))
            {
                $this->logg('No options attributes found', 1);
                $this->logg('HTML: '.$html);
            }
            $options = [];
            foreach ( $spconfig['attributes'] as $attribute )
            {
                foreach ( $attribute['options'] as $opt )
                {
                    $option = [];
                    if (!isset($opt['label']))
                    {
                        $this->logg('No label found for option', 1);
                        $this->logg('JSON: '.$json);
                        continue;
                    }
                    $option['label'] = $opt['label'];
                    if (!isset($opt['products'][0]))
                    {
                        $this->logg('No ID found for option', 1);
                        $this->logg('JSON: '.$json);
                        continue;
                    }
                    $option['id'] = $opt['products'][0];
                    $options[] = $option;
                }
            }
            foreach ( $options as $key => $option )
            {
                $qty = $this->regex('#id\['.$option['id'].'\]\s=\s(\d+)#', $html, 1);
                if (!$qty)
                {
                    $this->logg('No quantity found for option: '.$option['id'], 1);
                    continue;
                }
                $item['product_option'] = $option['label'];
                $item['qty_available'] = $qty;

                if ($item['qty_available'] != 0)
                    $this->write_item_details($item);
            }
        }
        else
        {
            $this->logg('No options found. Search for quantity', 1);
            foreach ( $this->xpath->query('//select[ @id = "qty" ]/option') as $node )
            {
                $qty = trim($node->getAttribute('value'));
            }
            $item['product_option'] = '';
            $item['qty_available'] = $qty;

            if ($item['qty_available'] != 0)
                $this->write_item_details($item);
        }
    }
}

$scraper = new ForeverNewScraper($argv);