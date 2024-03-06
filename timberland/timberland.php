<?php
/**
 * @file timberland.php
 * @brief File contains http://www.timberland.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class TimberlandScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class TimberlandScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Timberland', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'men/footwear',
            'men/clothing',
            'men/accessories',
            'women/footwear',
            'women/accessories',
            'kids/footwear'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/'.$this->category);
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
        $this->category_name = $this->get_node_value('//h1[ @class = "page-title" ]/span');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Get pages count
        $items_count = $this->get_node_value('//p[ @class = "toolbar-amount" ]/span[ last() ]');
        if (!$items_count)
        {
            $this->logg('No items count found', 1);
            $pages_count = 1;
        }
        else {
            $pages_count = ceil($items_count / 9);
        }
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page($url.'?p='.$page);
            $this->logg('Parse catalogue page '.$page);
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
        foreach ( $this->xpath->query('//a[ @class = "product-item-link" ]') as $node )
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
        $item['product_name'] = $this->get_node_value('//span[ @itemprop = "name" ]');
        $item['product_name'] = $item['product_name'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            return;
        }

        // Price
        $price = $this->get_node_value('//span[ contains(@id, "product-price") ]/span');
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

        // Original price
        $original_price = $this->get_node_value('//span[ contains(@id, "old-price") ]/span');
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

        // Product ID
        $product_id = $this->get_node_value('//div[ @itemprop = "sku" ]');
        if (!$product_id)
        {
            $this->logg('No product ID found', 2);
            $this->logg('HTML: '. $html);
            return;
        }

        // Variants
        if (!preg_match('#\"Magento_Swatches\/js\/swatch-renderer\":\s(\{[\s\S]+?\}),\s+?\"Magento_Swatches\/js\/configurable-customer-data\"#', $html))
        {
            // One variant only
            // Product ID
            $item['product_id'] = $product_id;
            if ($item['product_id'] == '') {
                $this->logg('No product ID found', 2);
                return;
            }
            // Filter out duplicates by ID
            if (in_array($item['product_id'], $this->product_ids)) {
                $this->logg('Product ID '.$item['product_id'].' already scraped', 1);
                return;
            }
            $this->product_ids[] = $item['product_id'];

            // Size
            $item['product_option'] = '';

            // Quantity
            $item['qty_available'] = 0;
            $this->write_item_details($item);
        }
        $json = $this->regex('#\"Magento_Swatches\/js\/swatch-renderer\":\s(\{[\s\S]+?\}),\s+?\"Magento_Swatches\/js\/configurable-customer-data\"#', $html, 1);
        $variants = json_decode($json, true);
        foreach ($variants['jsonConfig']['attributes'] as $attribute) {
            foreach ($attribute['options'] as $variant) {
                // Product ID
                $item['product_id'] = $product_id.'_'.$variant['id'];
                if ($item['product_id'] == '') {
                    $this->logg('No product ID found', 2);
                    $this->logg('JSON: '.$json);
                    return;
                }
                // Filter out duplicates by ID
                if (in_array($item['product_id'], $this->product_ids)) {
                    $this->logg('Product ID '.$item['product_id'].' already scraped', 1);
                    return;
                }
                $this->product_ids[] = $item['product_id'];

                // Size
                $item['product_option'] = $variant['label'];

                // Quantity
                $item['qty_available'] = 0;
                $this->write_item_details($item);

            }
        }
    }

}

$scraper = new TimberlandScraper($argv);