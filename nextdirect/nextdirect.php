<?php
/**
 * @file nextdirect.php
 * @brief File contains https://www.nextdirect.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class NextDirectScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class NextDirectScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('NextDirect', 'ZAR', $arg);
        $this->set_proxy();
        $this->parse_catalog($this->baseurl.'/shop/'.$this->category);
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

        $items_count = $this->get_node_value('//div[ @class = "ResultCount" ]/div');
        if (!$items_count)
        {
            $this->logg('No items count found', 2);
            return false;
        }
        $items_count = $this->regex('#(\d+)#', $items_count, 1);
        $pages_count = ceil($items_count / 24);
        $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        for ($page = 2; $page <= $pages_count; $page++)
        {
            $srt = ($page - 1) * 24;
            $page_html = $this->get_page($url.'/srt-'.$srt);
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
        // Get all items
        foreach ($this->xpath->query('//h2[ @class = "Title" ]/a') as $node)
        {
            $link = $node->getAttribute("href");
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
        $item['brand'] = $this->site_name;
        
        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @class = "Title" ]/h1');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            //$this->logg('HTML: '. $html);
            return;
        }

        // Price
        $price = $this->get_node_value('//div[ @class = "nowPrice" ]/span');
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
        $item['discount'] = 0;

        // Category
        $item['category'] = $this->category;

        // Product type
        $nodes = $this->xpath->query('//ul[ @class = "Breadcrumbs" ]/li');
        $i = 0;
        foreach ( $nodes as $node )
        {
            $cols = $this->xpath->query('./a', $node);
            foreach ( $cols as $col )
            {
                switch ($i)
                {
                    case 1: $item['product_type'] = trim($col->nodeValue); break;
                }
            }
            $i++;
        }
        if (!isset($item['product_type']))
        {
            $this->logg('No product type found', 1);
            $item['product_type'] = '';
        }

        // Get sizes
        $json = $this->regex('#var\sshotData\s=\s(\{[\s\S]+?\});#', $html, 1);
        $arr = json_decode($json, true);
        foreach ($arr['Styles'] as $style) {
            foreach ($style['Fits'] as $fit) {
                foreach ($fit['Items'] as $item_node) {
                    foreach ($item_node['Options'] as $option) {
                        if ($option['StockStatus'] == 'SoldOut')
                            continue;

                        // Product ID
                        $item['product_id'] = str_replace('-', '', $item_node['ItemNumber']);

                        // Product option
                        $item['product_option'] = $option['Name'];
                        if ($fit['Name'] != '') $item['product_option'] .= ' ' . $fit['Name'];
                        if ($item_node['Colour'] != '') $item['product_option'] .= ' ' . $item_node['Colour'];

                        $item['option_id'] = $option['Number'];

                        // Quantity
                        $item['qty_available'] = $this->get_qty($item);

                        // Add option id to get unique item id
                        $item['product_id'] .= '_'.$item['option_id'];

                        // Filter out duplicates by ID
                        if ( in_array($item['product_id'], $this->product_ids) )
                        {
                            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
                            return;
                        }
                        $this->product_ids[] = $item['product_id'];

                        if ($item['qty_available'] != 0)
                            $this->write_item_details($item);
                        else
                            $this->logg('Item sold out');
                    }
                }
            }
            break;  // First style only
        }
    }

    // Get item quantity
    function get_qty($item)
    {
        $this->logg('Get quantity');
        for ($i = 0; $i < 9; $i++) {
            $item_cnt = $i+1;
            $this->logg("Try to add $item_cnt item to cart. Item ID: {$item['product_id']} Option ID: {$item['option_id']}");
            $res = $this->add_to_cart($item);
            if ($res === -1) {
                $this->logg('Add to cart error', 2);
                return false;
            }
            if ($res === false) {
                return $i;
            }
        }
        return $i;
    }

    // Add item to cart (need to get quantity)
    function add_to_cart($item)
    {
        $postfields = [
            'id' => $item['product_id'],
            'option' => $item['option_id'],
            'chain' => 'A',
            'quantity' => 1
        ];
        $json = $this->post($this->baseurl.'/bag/add', $postfields);
        $arr = json_decode($json, true);
        foreach ($arr['ShoppingBag']['Items'] as $item_node) {
            if ( ($item_node['ItemNumber'] == $item['product_id']) &&
                 ($item_node['OptionNo'] == $item['option_id']) ) {
                if ($item_node['StockStatus'] == 'instock') {
                    $this->logg('In stock');
                    return true;
                }
                else {
                    $this->logg('Sold out');
                    return false;
                }
            }
        }
        return -1;
    }

}

$scraper = new NextDirectScraper($argv);