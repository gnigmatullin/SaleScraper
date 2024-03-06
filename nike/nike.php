<?php
/**
 * @file dc.php
 * @brief File contains https://www.nike.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class Scraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class NikeScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Nike', 'ZAR', $arg);
        $this->set_curl_options([
            CURLOPT_HTTPHEADER => [
                'accept-encoding: deflate, br',
                'accept-language: en-US;q=0.8,en;q=0.7',
                'referer: https://www.nike.com',
                'user-agent: Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.116 Safari/537.36'
            ]
        ]);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $categories = [
            'Mens Shoes' => '0f64ecc7-d624-4e91-b171-b83a03dd8550,16633190-45e5-4830-a068-232ac7aea82c',
            'Mens Clothing' => '0f64ecc7-d624-4e91-b171-b83a03dd8550,a00f0bb2-648b-4853-9559-4cd943b7d6c6',
            'Womens Shoes' => '16633190-45e5-4830-a068-232ac7aea82c,7baf216c-acc6-4452-9e07-39c2ca77ba32',
            'Womens Clothing' => 'a00f0bb2-648b-4853-9559-4cd943b7d6c6,7baf216c-acc6-4452-9e07-39c2ca77ba32'
        ];
        // Get all categories
        foreach ($categories as $category_name => $category_id) {
            $this->category = $category_name;
            $this->logg('Category: '.$this->category);
            $this->category_id = $category_id;
            $this->category_url = 'https://api.nike.com/cic/browse/v1?queryid=products&anonymousId=0C84DC23FA741608A75304CCFD35D3C8&endpoint=%2Fproduct_feed%2Frollup_threads%2Fv2%3Ffilter%3Dmarketplace(ZA)%26filter%3Dlanguage(en-GB)%26filter%3DemployeePrice(true)%26filter%3DattributeIds('.urlencode($this->category_id).')%26anchor%3D0%26count%3D24%26consumerChannelId%3Dd9a5bc42-4b9c-4976-858a-f159cf99c647';
            $this->parse_catalog($this->category_url);
            //if ($this->debug) break;
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
        $json = $this->get_page($url);
        //var_dump($json);
        $this->logg('Parse catalogue '.$url);
        $arr = json_decode($json, true);

        // Category name
        unset($this->category_name);
        $this->category_name = $this->category;
        if (!$this->category_name)
        {
            $this->logg('No category name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }
        $this->logg('Category name: '.$this->category_name);

        // Get pages count
        $pages_count = $arr['data']['products']['pages']['totalPages'];
        if (!$pages_count)
        {
            $this->logg('No pages count found');
            $pages_count = 1;
        }
        else
            $this->logg('Pages count: '.$pages_count);
        
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($arr);

        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $offset = ($page-1) * 24;
            $url = str_replace('anchor%3D0', 'anchor%3D'.$offset, $this->category_url);
            $json = $this->get_page($url);
            $this->logg('Parse catalogue page ' . $page);
            $arr = json_decode($json, true);
            $this->parse_catalog_page($arr);
            //if ($this->debug) break;
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($arr)
    {
        // Get items
        foreach ($arr['data']['products']['objects'] as $product)
        {
            $this->parse_item($product);
            //if ($this->debug) break;
            sleep(2);
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($product)
    {
        $this->logg('Parse item details');

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = 'https://www.nike.com/za/t/'.$product['publishedContent']['properties']['seo']['slug'].'/'.$product['productInfo'][0]['merchProduct']['styleColor'];

        // Brand
        $item['brand'] = $this->site_name;
        
        // Product id
        $item['product_id'] = $product['id'];
        if (!$item['product_id'])
        {
            $this->logg('No product id found', 2);
            //$this->logg('HTML: '. $html);
            //exit();
            return;
        }
        
        // Product name
        $item['product_name'] = $product['publishedContent']['properties']['title'];
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $product['productInfo'][0]['merchPrice']['currentPrice'];
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
        
        // Discount
        $item['discount'] = 0;

        // Category
        $item['category'] = $this->category_name;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = '';
        
        // Product option
        $item['product_option'] = $product['productInfo'][0]['merchProduct']['styleColor'];
        if (!$item['product_option'])
            $item['product_option'] = '';
        
        // Image URL
        $item['image_url'] = $product['publishedContent']['properties']['productCard']['properties']['squarishURL'];
        if (!$item['image_url'])
        {
            $this->logg('No image URL found', 2);
            return;
        }
        
        // Write to DB
        $this->write_item_details($item);
    }

}

$scraper = new NikeScraper($argv);