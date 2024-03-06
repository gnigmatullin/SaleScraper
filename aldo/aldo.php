<?php
/**
 * @file aldo.php
 * @brief File contains https://www.aldoshoes.co.za/ website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class AldoScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Aldo', 'ZAR', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'women/footwear/all-footwear',
            'women/handbags/all-handbags',
            'women/accessories/all-accessories',
            'men/footwear/all-footwear',
            'men/bags-accessories/all-bags-accessories',
            'sale',
            'call-it-spring/women/footwear/all-footwear',
            'call-it-spring/men/footwear/all-footwear'
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
        $this->category_name = $this->get_node_value('//h1[ @id = "ProductLandingHeaderTitle" ]/span/span');
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $items_count = $this->get_node_value('//div[ @class = "pager" ]/p[ @class = "amount" ]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            $pages_count = 1;
        }
        else
        {
            $items_count = $this->regex('#(\d+)#', $items_count, 1);
            $this->logg('Items count: '.$items_count);
            $pages_count = ceil($items_count / 25);
            $this->logg('Pages count: '.$pages_count);
        }
        
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
        foreach ( $this->xpath->query('//a[ @class = "c-product-tile__link-product product-image" ]') as $node )
        {
            $link = $node->getAttribute("href");
            //$this->logg($link);
            if ($link != '') $this->parse_item($link);
            if ($this->debug) break;
            sleep(2);
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
        if (strpos($this->category, 'call-it-spring') === false)
            $item['brand'] = $this->site_name;
        else
            $item['brand'] = 'Call It Spring';

        // Product name
        $item['product_name'] = $this->get_node_value('//div[ @class = "product-name" ]/span[ @class = "h1" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        $this->product_id = $item['product_name'];

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = '';

        // Get options
        $json = $this->regex('#var\sspConfig\s=\snew\sProduct\.Config\((\{[\s\S]+?\})\);#', $html, 1);
        $arr = json_decode($json, true);

        $item['price'] = $arr['basePrice'];

        $item['discount'] = 0;

        $attributes = [];
        foreach ($arr['attributes'] as $attr_id => $attribute) {
            $i = 0;
            foreach ($attribute['options'] as $option) {
                $attributes[$attr_id]['attr_id'] = $attribute['id'];
                $attributes[$attr_id]['options'][$i]['option_id'] = $option['id'];
                $attributes[$attr_id]['options'][$i]['option_label'] = $option['label'];
                $attributes[$attr_id]['options_cnt'] = $i;
                $i++;
            }
        }

        foreach ($attributes as $attr1_id => $attribute1) {
            foreach ($attribute1['options'] as $option1) {
                foreach ($attributes as $attr2_id => $attribute2) {
                    if ($attr1_id == $attr2_id) continue;
                    foreach ($attribute2['options'] as $option2) {

                        // Product option
                        $item['product_option'] = trim($option1['option_label'].' '.$option2['option_label']);
                        // Add option to product ID
                        $item['product_id'] = $this->product_id.'_'.$item['product_option'];
                        $item['product_id'] = str_replace(' ', '_', $item['product_id']);

                        // Filter out duplicates by ID
                        if (in_array($item['product_id'], $this->product_ids)) {
                            $this->logg('Product ID '.$item['product_id'].' already scraped', 1);
                            return;
                        }
                        $this->product_ids[] = $item['product_id'];

                        // Get quantity
                        $item['qty_available'] = 0;

                        // Write to DB
                        $this->write_item_details($item);
                    }
                }
            }
            break;
        }

        return true;
    }

}

$scraper = new AldoScraper($argv);