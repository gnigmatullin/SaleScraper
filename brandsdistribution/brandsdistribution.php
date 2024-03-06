<?php
/**
 * @file brandsdistribution.php
 * @brief File contains https://www.brandsdistribution.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class BrandDistributionScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class BrandDistributionScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('BrandsDistribution', 'EUR', $arg);
        $this->set_cookies($this->category);
        $this->login();
        $this->parse_catalog($this->baseurl.'/current/catalog/?tag_4='.$this->category);
    }

    /**
     * @brief Custom POST request for login to site
     * @param string $url           [Request URL]
     * @param array  $postfields    [Array of POST fields passed in request]
     * @return int|mixed            [-1 if error | HTML code if success]
     */
    function post($url, $postfields)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $this->cookies_file,
            CURLOPT_COOKIEFILE => $this->cookies_file,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['x-requested-with: XMLHttpRequest']
        );
        $this->logg("Send POST $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 2);
            return -1;
        }
        curl_close($ch);
        return $html;
    }

    /**
     * @brief Login to the site
     * @details Pass username and password in POST request
     */
    private function login()
    {
        $this->logg('Login to website');
        $postfields = 'username=johndoeshopper@webmail.co.za&password=johndoe123';
        $json = $this->post($this->baseurl.'/restful/auth/login', $postfields);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error_msg(), 2);
            $this->logg($json);
            exit();
        }
        if ($arr['success'] != true)
        {
            $this->logg('Login error', 2);
            $this->logg($json);
            exit();
        }
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
        $html = $this->get_page($url);
        $this->logg('Parse catalogue '.$url);
        $this->loadDOM($html);
        $pages_count = $this->get_node_value('//ul[ @class = "pagination" ]/li[last()-1]/a');
        if (!$pages_count)
        {
            $this->logg('No pagination found', 1);
            $pages_count = 1;
        }
        $this->logg('Pages count: '.$pages_count);
        $this->logg('Parse catalogue page 1');
        $this->parse_catalog_page($html);
        for ($page = 2; $page <= $pages_count; $page++)
        {
            $page_html = $this->get_page(str_replace('/catalog/?', '/catalog/'.$page.'?', $url));
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
        foreach ( $this->xpath->query('//div[ @class = "row catalog-layout catalog-list" ]/div[ @class = "catalog-product" ]') as $node )
        {
            $this->parse_item($node);
            //if ($this->>debug) break;
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($node)
    {
        $this->logg('Parse item details');

        $item = array();
        $item['tracking_date'] = date('Y-m-d');
        
        // URL
        $item['product_url'] = $this->get_attribute('.//div[ @class = "product-id" ]/a', 'href', $node);
        if (!$item['product_url'])
        {
            $this->logg('No URL found', 1);
            $this->logg('HTML: '. $node->nodeValue);
            return;
        }
        $item['product_url'] = $this->baseurl.$item['product_url'];

        // Product ID
        $item['product_id'] = $item['product_url'];

        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
            return;
        }
        $this->product_ids[] = $item['product_id'];

        // Brand
        $item['brand'] = $this->get_node_value('.//span[ @class = "product-brand" ]', $node);
        if (!$item['brand'])
        {
            $this->logg('No brand found', 1);
            //$this->logg('HTML: '. $node->nodeValue);
            return;
        }
        
        // Product name
        $item['product_name'] = $this->get_node_value('.//span[ @class = "product-name" ]', $node);
        if (!$item['product_name'])
        {
            $this->logg('No product name found', 1);
            //$this->logg('HTML: '. $node->nodeValue);
            return;
        }

        // Price
        $price = $this->get_node_value('.//span[ @itemprop = "price" ]', $node);
        if ($price)
        {
            $price = $this->regex('#([\d\,\.]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found');
            $item['price'] = 0;
        }
        
        // Discounted price
        $discounted_price = $this->get_node_value('.//span[ @class = "price-label was-price linedthrough" ]', $node);
        if ($discounted_price)
        {
            $discounted_price = $this->regex('#([\d\,\.]+)#', $discounted_price, 1);
            $item['discounted_price'] = str_replace(',', '', $discounted_price);
            // Discount
            $item['discount'] = ceil( ($item['discounted_price'] - $item['price']) / $item['discounted_price'] * 100 );
        }
        else
        {
            $this->logg('No discounted price found', 1);
            $item['discount'] = 0;
        }
        
        // Category
        $item['category'] = $this->get_node_value('.//span[ contains( ., "Gender:" ) ]/following-sibling::span', $node);
        if (!$item['category'])
        {
            $this->logg('No category found', 1);
            $item['category'] = '';
        }
        
        // Product type
        $item['product_type'] = $this->get_node_value('.//span[ @class = "product-category" ]', $node);
        if (!$item['product_type'])
        {
            $this->logg('No product type found', 1);
            $item['product_type'] = '';
        }
        
        // Size
        $item['product_option'] = $this->get_attribute('.//meta[ @itemprop = "model" ]', 'content', $node);
        if (!$item['product_option'])
        {
            $this->logg('No size found', 1);
            //$this->logg('HTML: '. $node->nodeValue);
            return;
        }
        
        // Quantity
        $item['qty_available'] = $this->get_attribute('.//input[ @class = "form-control i-number" ]', 'data-max', $node);
        if (!$item['qty_available'])
        {
            $this->logg('No quantity found', 1);
            //$this->logg('HTML: '. $node->nodeValue);
            return;
        }

        if ($item['qty_available'] != 0)
            $this->write_item_details($item);
    }

}

$scraper = new BrandDistributionScraper($argv);