<?php
/**
 * @file greenc`ross.php
 * @brief File contains https://www.green-cross.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class GreenCrossScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class GreenCrossScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('GreenCross', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'women',
            'men'
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
        $this->category_name = $this->get_node_value('//h1/span');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        // Get pages
        $this->last_page = false;
        $page = 2;
        while (!$this->last_page) {
            $page_html = $this->get_page($url.'?p='.$page);
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($page_html);
            $page++;
            if ($page > 100) break;
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

        // Check for last page
        if ($this->get_attribute('//a[ @class = "action  next" ]', 'href') == '')
            $this->last_page = true;

        // Get items
        foreach ( $this->xpath->query('//div[ @class = "product-item-img" ]/a') as $node )
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

        // Product ID
        $item['product_id'] = $this->regex('#(\d+)$#', $url, 1);

        // Brand
        $item['brand'] = $this->site_name;

        // Product name
        $item['product_name'] = $this->get_node_value('//span[ @itemprop = "name" ]');
        $item['product_name'] = $item['product_name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            return;
        }

        // Price
        $price = $this->get_node_value('//span[ @class = "price" ]');
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
        $item['discount'] = 0;

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            return;
        }

        // Product type
        $item['product_type'] = '';

        // Sizes
        $size_string = $this->get_node_value('//td[ @data-th = "Size" ]');
        $sizes = explode(',', $size_string);
        if (count($sizes) == 0) {
            $this->logg('No sizes found', 1);
            $sizes = [''];  // Empty size
        }

        // Variants
        if (!preg_match('#\"\[data-role=swatch-options\]\":\s\{[\s\S]+?</script>#', $html))
        {
            $this->logg('No variants found', 2);
            return;
        }
        $json = '{'.$this->regex('#(\"\[data-role=swatch-options\]\":\s\{[\s\S]+?)</script>#', $html, 1);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error_msg(), 2);
            $this->logg('JSON: '.$json);
            return;
        }

        foreach ($sizes as $size) {
            foreach ($arr['[data-role=swatch-options]']['Magento_Swatches/js/swatch-renderer']['jsonSwatchConfig'] as $product) {
                foreach ($product as $variant_id => $variant) {
                    $this->get_variant($item, $size, $variant_id, $variant);
                }
            }
        }
    }

    /**
     * @brief Get variant details
     * @details Get variant details from array and write to DB
     * @param array $item           [Array of item details]
     * @param string $size          [Size code]
     * @param string $variant_id    [Variant ID]
     * @param array $variant        [Array of variant details]
     */
    private function get_variant($item, $size, $variant_id, $variant)
    {
        // Product ID
        $item['product_id'] .= $variant_id . $size;
        if ($item['product_id'] == '') {
            $this->logg('No product ID found', 2);
            exit();
        }
        // Filter out duplicates by ID
        if (in_array($item['product_id'], $this->product_ids)) {
            $this->logg('Product ID '.$item['product_id'].' already scraped');
            return;
        }
        $this->product_ids[] = $item['product_id'];

        // Product option
        $item['product_option'] = $variant['label'].' '.$size;

        $this->write_item_details($item);
    }

}

$scraper = new GreenCrossScraper($argv);