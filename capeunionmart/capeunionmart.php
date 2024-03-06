<?php
/**
 * @file capeunionmart.php
 * @brief File contains https://www.capeunionmart.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class CapeUnionMartScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class CapeUnionMartScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('CapeUnionMart', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'clothing/men-s-clothing',
            'clothing/women-s-clothing',
            'clothing/kids-clothing',
            'footwear/men-s-footwear',
            'footwear/women-s-footwear',
            'footwear/kids-footwear'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category);
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

        // Category name
        unset($this->category_name);
        $this->category_name = $this->get_node_value('//h1');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Get pages count
        $items_count = $this->get_node_value('//span[ @class = "limited-amount" ]');
        if (!$items_count)
        {
            $this->logg('No items count found', 1);
            $pages_count = 1;
        }
        else {
            $items_count = $this->regex('#(\d+)\smatches#', $items_count, 1);
            $pages_count = ceil($items_count / 24);
        }
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
        $item['brand'] = $this->get_node_value('//th[ contains(., "Brands") ]/following-sibling::td');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 1);
            $item['brand'] = $this->site_name;
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @class = "product-name" ]/h1');
        $item['product_name'] = $item['product_name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            return;
        }

        // Price
        $price = $this->get_node_value('//span[ contains(@id, "product-price") ]');
        if ($price)
        {
            $price = $this->regex('#([\d\.,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Original price
        $original_price = $this->get_node_value('//span[ contains(@id, "old-price") ]');
        if ($original_price != '')
        {
            $original_price = $this->regex('#([\d\.,]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            return;
        }

        // Product type
        $item['product_type'] = '';

        // Variants
        if (!preg_match('#data-lookup(=|\s=\s)\"\{[\s\S]+?\}\"#', $html))
        {
            $this->logg('No variants found', 2);
            return;
        }
        $arr = $this->regex('#data-lookup(=|\s=\s)\"(?P<json>\{[\s\S]+?\})\"#', $html);
        $json = str_replace("'", '"', $arr['json']);
        $json = str_replace('\"', "'", $json);
        $json = str_replace('\n', '', $json);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            return;
        }
        foreach ($arr as $product) {
            if (isset($product['id']))
                $this->parse_variant($item, $product);
            else {
                foreach ($product as $variant_id => $variant)
                    $this->parse_variant($item, $variant);
            }
        }
    }

    /**
     * @brief Parse item variants
     * @param array $item   [Array of item details]
     * @param $variant      [Array of variant details]
     */
    private function parse_variant($item, $variant)
    {
        // Product ID
        $item['product_id'] = $variant['id'];
        if ($item['product_id'] == '') {
            $this->logg('No product ID found', 2);
            $this->logg('JSON: '.json_encode($variant));
            exit();
        }
        // Filter out duplicates by ID
        if (in_array($item['product_id'], $this->product_ids)) {
            $this->logg('Product ID '.$item['product_id'].' already scraped');
            return;
        }
        $this->product_ids[] = $item['product_id'];

        // Product option
        $item['product_option'] = trim($variant['x3_colour'].' '.$variant['x3_size']);

        // Quantity
        $item['qty_available'] = $variant['qty'];
        if ($item['qty_available'] != 0)
            $this->write_item_details($item);
    }

}

$scraper = new CapeUnionMartScraper($argv);