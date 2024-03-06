<?php
/**
 * @file mrp.php
 * @brief File contains https://www.mrp.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class MRPScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class MRPScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('MRP', 'ZAR', $arg);
        $this->set_cookies();
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $this->baseurl .= '/en_za';
        $html = $this->get_page($this->baseurl);
        $this->logg('Parse categories '.$this->baseurl);
        $this->loadDOM($html);

        // Get all categories
        $categories = [];
        foreach ( $this->xpath->query('//a[ contains(@class, "menu-link") ]') as $node )
        {
            $link = $node->getAttribute("href");
            if ( (strpos($link, "shop-by-category/") === false) && (strpos($link, "kids/boys") === false) && (strpos($link, "kids/girls") === false))
                continue;
            if (!in_array($categories, $link)) $categories[] = str_replace('/en_eu/', '/en_za/', $link);
        }
        //var_dump($categories);

        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->category);
            if ($this->debug) break;
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
        // Get pages count
        $items_count = $this->get_node_value('//div[ @class = "amount right" ]/span');
        if (!$items_count)
        {
            $this->logg('No items count found');
            return false;
        }
        $items_count = $this->regex('#(\d+)#', $items_count, 1);
        $pages_count = ceil($items_count / 36);
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
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
        foreach ( $this->xpath->query('//h5[ @class = "product-name" ]/a') as $node )
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
        if (!$html) return false;
        $this->logg('Parse item details from '.$url);
        $this->loadDOM($html);

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $url;

        // Brand
        $item['brand'] = $this->regex('#"brand":\s"([\s\S]+?)"#', $html, 1);
        $this->logg($item['brand']);
        if (!$item['brand'])
        {
            $this->logg('No brand found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @class = "product-name" ]/h1');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Category
        $item['category'] = $this->get_node_value('//li[ @class = "home" ]/following-sibling::li[1]/a');
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = $this->get_node_value('//li[ @class = "home" ]/following-sibling::li[2]/a');
        if (!isset($item['product_type']))
        {
            $this->logg('No product_type found', 2);
            $item['product_type'] = '';
        }

        // Get options
        $this->parse_options($item, $html);

    }

    /**
     * @brief Parse item options
     * @details Get items options from HTML and write to DB
     * @param array $item   [Array of item details]
     * @param string $html  [Page HTML]
     */
    private function parse_options($item, $html)
    {
        $this->logg('Parse product options');
        $json = $this->regex('#productData\[productId\]\.jsonData\s=\s({[\s\S]+?});#', $html, 1);
        $options = json_decode($json, true);
        foreach ($options as $option)
        {

            // Product ID
            $item['product_id'] = $option['sku'];
            if ($item['product_id'] == '')
            {
                $this->logg('No product ID found', 1);
                continue;
            }
            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
                return;
            }
            $this->product_ids[] = $item['product_id'];

            // Price
            $price = $option['price'];
            if ($price != '')
            {
                $price = $this->regex('#([\d\,\.]+)#', $price, 1);
                $item['price'] = str_replace(',', '', $price);
            }
            else
            {
                $this->logg('No price found');
                $item['price'] = 0;
            }

            // Discount
            $item['discount'] = 0;

            // Product option
            $item['product_option'] = $option['mrp_colour'].' '.$option['mrp_size'];
            if ($item['product_option'] == '')
            {
                $this->logg('No option found', 1);
                continue;
            }

            // Quantity
            $item['qty_available'] = $option['qty'];
            if ($item['qty_available'] == '')
            {
                $this->logg('No quantity found', 1);
                continue;
            }

            if ($item['qty_available'] != 0)
                $this->write_item_details($item);
        }
    }

}

$scraper = new MRPScraper($argv);