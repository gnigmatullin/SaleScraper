<?php
/**
 * @file 6pm.php
 * @brief File contains https://www.6pm.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class SixPMScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class SixPMScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('6pm', 'USD', $arg);
        if (!isset($arg[1])) {
            $this->logg('No category name specified in the arguments', 2);
            exit();
        }
        $this->parse_categories($arg[1]);
    }

    /**
     * @brief Parse categories
     * @details Get category name from the arguments and run parse_catalog function for each category
     * @param string $category  [String with category names split by commas]
     */
    private function parse_categories($category)
    {
        if (!isset($category) || ($category==''))
        {
            $this->logg('No category specified', 2);
            exit();
        }
        $categories = explode(',', $category);
        foreach ($categories as $category)
        {
            $category = trim($category);
            $this->category = $this->regex('#^(\S+?)\/#', $category, 1);
            $this->logg('Category: '.$this->category);

            $this->scraper_log = './log/'.strtolower($this->site_name).'_'.str_replace('/', '_', $category).'.log';
            if (file_exists($this->scraper_log)) unlink($this->scraper_log);

            $this->parse_catalog($this->baseurl.'/'.$category.'.zso');
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
        $this->loadDOM($html);

        $items_count = $this->get_node_value('//span[ contains( ., "items found" ) ]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            return false;
        }
        $items_count = $this->regex('#(\d+)#', $items_count, 1);
        $pages_count = ceil($items_count / 100);
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        for ($page = 1; $page < $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'?p='.$page);
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($page_html);
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
        $this->loadDOM($html);
        // Get items
        foreach ( $this->xpath->query('//a[ @itemprop = "url" ]') as $node )
        {
            $link = $this->baseurl.$node->getAttribute("href");
            // Filter out duplicates by URL
            if ( in_array($link, $this->product_urls) )
            {
                $this->logg('Product URL ['.$link. '] already scraped');
                continue;
            }
            $this->product_urls[] = $link;
            $this->parse_item($link);
            //if ($this->debug) break;
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details and write to DB
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($url)
    {
        $html = $this->get_page($url);
        if (!$html) return false;
        $this->logg('Parse item details from '.$url);
        $json = $this->regex('#<script>window\.__INITIAL_STATE__\s=\s(\{[\s\S]+?\});</script>#', $html, 1);
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
        if (!isset($arr['product']['detail']['brandName']))
        {
            $this->logg('No brand found');
            $this->logg('JSON: '. $json);
            return false;
        }
        $item['brand'] = $arr['product']['detail']['brandName'];
        
        // Product name
        if (!isset($arr['product']['detail']['productName']))
        {
            $this->logg('No product name found');
            $this->logg('JSON: '. $json);
            return false;
        }
        $item['product_name'] = $arr['product']['detail']['productName'];

        // Category
        $item['category'] = $this->category;

        // Product type
        if (!isset($arr['product']['detail']['defaultCategory']))
        {
            $this->logg('No product type found');
            $item['product_type'] = '';
        }
        else
            $item['product_type'] = $arr['product']['detail']['defaultCategory'];
        
        // Get all styles
        foreach ($arr['product']['detail']['styles'] as $style)
        {
            // Product URL
            if (!isset($style['productUrl']))
            {
                $this->logg('No product URL found');
                continue;
            }
            $item['product_url'] = $this->baseurl . urldecode($style['productUrl']);
            
            // Filter out duplicates by URL
            if ( in_array($item['product_url'], $this->styles_urls) )
            {
                $this->logg('Product URL ['.$item['product_url']. '] already scraped');
                continue;
            }
            $this->styles_urls[] = $item['product_url'];
            
            // Price
            if (!isset($style['price']))
            {
                $this->logg('No price found');
                continue;
            }
            $price = $this->regex('#([\d\,\.]+)#', $style['price'], 1);
            $item['price'] = str_replace(',', '', $price);
            
            // Discount
            if (!isset($style['percentOff']))
            {
                $this->logg('No discount found');
                $item['discount'] = 0;
            }
            else {
                $discount = $style['percentOff'];
                $item['discount'] = $this->regex('#(\d+)#', $discount, 1);
            }
            
            // Get all sizes
            foreach ($style['stocks'] as $stock)
            {
                // Product ID
                if (!isset($stock['stockId']))
                {
                    $this->logg('No product ID found');
                    continue;
                }
                $item['product_id'] = $stock['stockId'];
                
                // Filter out duplicates by ID
                if ( in_array($item['product_id'], $this->product_ids) )
                {
                    $this->logg('Product ID '.$item['product_id']. ' already scraped');
                    continue;
                }
                $this->product_ids[] = $item['product_id'];
                
                // Product option
                $item['product_option'] = '';
                if (isset($style['color']))
                    $item['product_option'] = $style['color'];
                if (isset($stock['size']))
                    $item['product_option'] .= ' - ' . $stock['size'];
                
                // Qty available
                $item['qty_available'] = '';
                if (isset($stock['onHand']))
                    $item['qty_available'] = $stock['onHand'];

                // Write to db
                if ($item['qty_available'] != 0)
                    $this->write_item_details($item);
            }
        }
        return true;
    }

}

$scraper = new SixPMScraper($argv);