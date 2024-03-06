<?php
/**
 * @file brandalley.php
 * @brief File contains https://www.brandalley.co.uk website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class BrandAlleyScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class BrandAlleyScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('BrandAlley', 'GBP', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'women/clothing',
            'women/shoes',
            'women/accessories-handbags',
            'women/lingerie',
            'men/clothing',
            'men/shoes',
            'men/accessories',
            'kids/clothing',
            'kids/shoes',
            'kids/nursery-prams',
            'kids/games-toys',
            'beauty/skincare',
            'beauty/body-care',
            'beauty/hair-care-styling',
            'beauty/makeup',
            'sports-leisure/womens-clothing',
            'sports-leisure/mens-clothing',
            'sports-leisure/leisure-travel',
            'sports-leisure/sport-accessories'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category.'.html');
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
        $items_count = $this->get_node_value('//h2[ contains(., "Refine") ]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            return false;
        }
        $items_count = $this->regex('#(\d+)#', $items_count, 1);
        $pages_count = ceil($items_count / 60);
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

        // Brand
        $item['brand'] = $this->get_node_value('//span[ @itemprop = "brand" ]');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//span[ @class = "name-title" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Product ID
        $item['product_id'] = $this->get_attribute('//input[ @name = "product" ]', 'value');
        if (!$item['product_id'])
        {
            $this->logg('No product ID');
            $this->logg('HTML: '. $html, 2);
            exit();
        }

        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped');
            return;
        }
        $this->product_ids[] = $item['product_id'];

        // Price
        $price = $this->get_node_value('//span[ @itemprop = "price" ]');
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
        $discount = $this->get_node_value('//span[ @class = "discount" ]');
        $item['discount'] = $this->regex('#([\d\.\,]+)#', $discount, 1);
        if (!$item['discount'])
        {
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->regex('#^(\S+)/#', $this->category, 1);
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = $this->regex('#/(\S+)$#', $this->category, 1);
        if (!isset($item['product_type']))
        {
            $this->logg('No product_type found', 2);
            exit();
        }

        // Sizes
        $this->parse_options($item);

    }

    /**
     * @brief Parse item options
     * @details Get items options from ajax call and write to DB
     * @param array $item   [Array of item details]
     */
    private function parse_options($item)
    {
        $json = $this->get_page($this->baseurl.'/configurabledisplay/ajax/getjs?productid='.$item['product_id']);
        $this->logg('Parse options for ID '.$item['product_id']);
        $arr = json_decode($json, true);
        foreach ($arr['attributes'] as $attribute)
        {
            if ($attribute['code'] != 'size') continue;
            foreach ($attribute['options'] as $option)
            {
                $item['product_option'] = $option['label'];
                $item['product_option'] = preg_replace('#-\sOnly\s\d+\sleft#i', '', $item['product_option']);
                $item['product_option'] = preg_replace('#-\sIn\sStock#i', '', $item['product_option']);
                $item['product_option'] = trim($item['product_option']);
                $item['qty_available'] = $option['data_qty'];
                if ($item['qty_available'] != 0)
                    $this->write_item_details($item);
            }
        }
    }

}

$scraper = new BrandAlleyScraper($argv);