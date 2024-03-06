<?php
/**
 * @file perfumeuae.php
 * @brief File contains https://perfumeuae.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class PerfumeuaeScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class PerfumeuaeScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Perfumeuae', 'AED', $arg);
        $this->use_tor();
        $this->parse_catalog($this->baseurl.'/shop/');
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all categories
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse catalogue '.$url);
        $this->loadDOM($html);

        // Get all categories
        foreach ( $this->xpath->query('//li[ contains(@class, "product-category") ]/a') as $node ) {
            $link = $node->getAttribute("href");
            $this->parse_category($link);
        }
    }

    /**
     * @brief Parse category
     * @details Get category page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_category($url)
    {
        $html = $this->get_page($url);
        $this->logg('Parse category '.$url);
        $this->loadDOM($html);

        // Category name
        $this->category = $this->regex('#/product-category/(.+)/#', $url, 1);
        $this->logg('Category name: '.$this->category);

        $pages_count = $this->get_node_value('//nav[ @class = "woocommerce-pagination" ]/a[ last()-1 ]');
        if (!$pages_count)
        {
            $this->logg('No pages count found');
            $pages_count = 1;
        }
        $this->logg('Pages count: '.$pages_count);

        $this->logg('Parse category page 1');
        $this->parse_category_page($html);

        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'page/'.$page);
            $this->logg('Parse category page ' . $page);
            $this->parse_category_page($page_html);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_category_page($html)
    {
        $this->loadDOM($html);
        // Get all items
        foreach ( $this->xpath->query('//h3[ @class = "product-title" ]/a') as $node )
        {
            $link = $node->getAttribute("href");
            // Filter out duplicates by URL
            if ( in_array($link, $this->product_urls) )
            {
                $this->logg('Product URL '.$link. ' already scraped');
                continue;
            }
            $this->product_urls[] = $link;
            $this->parse_item($link);
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
        $item['brand'] = $this->get_node_value('//div[ contains( ., "Brands:" ) ]/a');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 1);
            $item['brand'] = $this->site_name;
        }
        
        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }
        
        // Product ID
        $item['product_id'] = $this->get_attribute('//button[ @name = "add-to-cart" ]', 'value');
        if (!$item['product_id'])
        {
            $this->logg('No product ID found', 2);
            //$this->logg('HTML: '. $html);
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
        $price = $this->get_node_value('//ins/span[ @class = "woocommerce-Price-amount amount" ]');
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
        $del_price = $this->get_node_value('//del/span[ @class = "woocommerce-Price-amount amount" ]');
        if ($del_price)
        {
            $del_price = $this->regex('#([\d,]+)#', $del_price, 1);
            $item['del_price'] = str_replace(',', '', $del_price);
            $item['discount'] = ceil(($item['del_price'] - $item['price']) / $item['del_price'] * 100);
        }
        else
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->get_node_value('//div[ @class = "product_meta" ]/span[ contains( ., "Tag" ) ]/a');
        if (!$item['category'])
        {
            $this->logg('No category found', 1);
            $item['category'] = '';
        }
        
        // Product type
        $item['product_type'] = $this->get_node_value('//div[ @class = "product_meta" ]/span[ contains( ., "Categor" ) ]/a');
        if (!$item['product_type'])
        {
            $this->logg('No product type found', 1);
            $item['product_type'] = '';
        }

        // Product option
        $item['product_option'] = '';
        
        // Quantity
        $item['qty_available'] = $this->get_attribute('//input[ @name = "quantity" ]', 'max');
        if (!$item['qty_available'])
        {
            $item['qty_available'] = $this->get_attribute('//input[ @name = "quantity" ]', 'value');
            if (!$item['qty_available'])
            {
                $this->logg('No quantity found', 2);
                //$this->logg('HTML: '. $html);
                return;
            }
        }

        // Write item
        $this->write_item_details($item);
    }
}

$scraper = new PerfumeuaeScraper($argv);