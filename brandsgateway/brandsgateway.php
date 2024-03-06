<?php
/**
 * @file brandsgateway.php
 * @brief File contains https://brandsgateway.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class BrandsGatewayScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class BrandsGatewayScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('BrandsGateway', 'EUR', $arg);
        $this->set_cookies();
        $this->login();

        $cookies = file_get_contents($this->cookies_file);
        $logged_in = $this->regex('#(wordpress_logged_in_.+)#', $cookies, 1);
        $logged_in = str_replace("\t", "=", $logged_in);
        $this->logg('logged_in: '.$logged_in);
        $this->set_curl_options([CURLOPT_HTTPHEADER => ['cookie: '.$logged_in]]);
        //$this->set_curl_options([CURLOPT_HTTPHEADER => ['cookie: wordpress_logged_in_3b7f5b492524460bc2562584ae5f45e0=customer3043%7C1540134988%7CfijFykjpQXvikSqkntyYsnBs1y0rmqLueQgBYstbTHL%7C71e756e55731eba0f665a6bc15a0bbcdc82977a396353021c02429d88f6a41dd']]);

        $categories = [
            'clothing',
            'shoes',
            'bags',
            'accessories',
            'jewelry',
            'stocklots'
        ];

        // Get all categories
        foreach ($categories as $category) {
            $this->category = $category;
            $this->logg('Category: '.$this->category);
            $this->parse_catalog($this->baseurl.'/category/'.$this->category);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Login to the site
     * @details Pass username and password in POST request
     */
    private function login()
    {
        $this->logg('Login to website');
        $this->set_curl_options([CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded',
                                                        'Origin: https://brandsgateway.com',
                                                        'Referer: https://brandsgateway.com/my-account/',
                                                        'Upgrade-Insecure-Requests: 1']]);
        $postfields = [
            'username'=>'joeschmoshopper@gmail.com',
            'password' => 'joeschmo123',
            'woocommerce-login-nonce' => '513ed8eb14',
            '_wp_http_referer' => '/my-account/',
            'login' => 'Log in'
        ];
        $this->post($this->baseurl.'/my-account', $postfields);
        $this->logg('Login success');
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog($url)
    {
        //$this->unset_cookies();
        $html = $this->get_page($url);
        $this->logg('Parse catalogue '.$url);
        $this->loadDOM($html);
        // Get pages count
        $items_count = $this->get_node_value('//p[ @class = "woocommerce-result-count" ]');
        if (!$items_count)
        {
            $this->logg('No items count found');
            return false;
        }
        $items_count = $this->regex('#(\d+)\sresults#', $items_count, 1);
        $pages_count = ceil($items_count / 72);
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        // Get pages
        for ($page = 2; $page <= $pages_count; $page++)
        {
            //$this->unset_cookies();
            $page_html = $this->get_page($url.'/page/'.$page);
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
        foreach ( $this->xpath->query('//a[ @class = "woocommerce-LoopProduct-link woocommerce-loop-product__link" ]') as $node )
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
        //$this->set_cookies();
        $html = $this->get_page($url);
        $this->logg('Parse item details from '.$url);
        $this->loadDOM($html);

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $url;

        // Brand
        $item['brand'] = $this->get_node_value('//div[ @class = "brand-name" ]');
        if (!$item['brand'])
        {
            $this->logg('No brand found', 1);
            //$this->logg('HTML: '. $html);
            //exit();
            return;     // Some of items haven't brand, skip them
        }

        // Product name
        $item['product_name'] = $this->get_node_value('//h1[ @class = "product_title entry-title" ]');
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 2);
            $this->logg('HTML: '. $html);
            exit();
        }

        // Price
        $price = $this->get_node_value('//div[ @class = "sale" ]/price/span');
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
        $original_price = $this->get_node_value('//div[ @class = "regular" ]/price/span');
        if ($original_price)
        {
            $original_price = $this->regex('#([\d,]+)#', $original_price, 1);
            $item['original_price'] = str_replace(',', '', $original_price);
            $item['original_price'] = round($original_price, 2);
            $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
        }
        else
        {
            $this->logg('No discount found', 1);
            $item['discount'] = 0;
        }

        // Category
        $item['category'] = $this->category;
        if (!isset($item['category']))
        {
            $this->logg('No category found', 2);
            exit();
        }

        // Product type
        $item['product_type'] = $this->get_node_value('//span[ @class = "posted_in" ]/a[last()]');
        if (!isset($item['product_type']))
        {
            $this->logg('No product_type found', 2);
            exit();
        }

        // Get all variations
        foreach ($this->xpath->query('//tr[ contains(@class, "instock is_purchasable") ]') as $row) {

            // Product ID
            $item['product_id'] = $this->get_attribute('.//input[ @name = "variation_id" ]', 'value', $row);
            if (!$item['product_id'])
            {
                $this->logg('No product ID', 2);
                exit();
            }

            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
                continue;
            }
            $this->product_ids[] = $item['product_id'];

            // Product option
            $item['product_option'] = $this->get_node_value('.//td[ contains(@class, "optionscol") ]', $row);
            if (!$item['product_option'])
            {
                $this->logg('No product option', 1);
                $item['product_option'] = '';
            }

            // Quantity
            $qty = $this->get_node_value('.//span[ contains(@class, "instock") ]', $row);
            if (!preg_match('#\d+#', $qty))
            {
                $this->logg('Quantity not found: '.$qty, 1);
                continue;
            }
            $item['qty_available'] = $this->regex('#(\d+)#', $qty, 1);
            if (!$item['qty_available'])
            {
                $this->logg('No qty available', 1);
                continue;
            }

            if ($item['qty_available'] != 0)
                $this->write_item_details($item);

        }

    }

}

$scraper = new BrandsGatewayScraper($argv);