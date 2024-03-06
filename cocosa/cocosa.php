<?php
/**
 * @file cocosa.php
 * @brief File contains https://www.cocosa.co.uk website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class CocosaScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class CocosaScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('Cocosa', 'GBP', $arg);

        $this->apiurl = $this->baseurl.'/api/shop/shop/v2/accounts/E9C291A6-80E1-4CD3-AF3E-4107BEBC376A/products';
        $this->apiurl2 = $this->baseurl.'/api/shop/product/v1/accounts/E9C291A6-80E1-4CD3-AF3E-4107BEBC376A/products/';

        // Get category name and id from $this->category
        $category = $this->category;
        $this->category = $this->regex('#^(\S+)\/#', $category, 1);
        $this->logg('Category: '.$this->category);
        $this->category_id = $this->regex('#\/(\S+)$#', $category, 1);
        $this->logg('Category ID: '.$this->category_id);

        // Parse category
        $this->parse_catalog($this->baseurl.'/'.$this->category);
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog()
    {
        $this->logg('Parse category: '.$this->category);
        switch ($this->category)
        {
            case 'women-clothing': $c = 'Women>>>Clothing'; break;
            case 'women-accessories': $c = 'Women>>>Accessories'; break;
            case 'women-footwear': $c = 'Women>>>Footwear'; break;
            case 'men-clothing': $c = 'Men>>>Clothing'; break;
            case 'men-accessories': $c = 'Men>>>Accessories'; break;
            case 'men-footwear': $c = 'Men>>>Footwear'; break;
            case 'kids-and-toys-clothing': $c = 'Kids & Toys>>>Clothing'; break;
            case 'kids-and-toys-shoes': $c = 'Kids & Toys>>>Shoes'; break;
            default:
                $this->logg('Unknown category name: '.$this->category, 2);
                exit();
        }
        $url = $this->apiurl.'?q=&pn=0&ps=50&c=["'.urlencode($c).'"]';
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 1);
            $this->logg($json);
            return;
        }
        // Get pages count
        if (!isset($arr['total']))
        {
            $this->logg('No products count found', 1);
            return;
        }
        $pages_count = ceil($arr['total'] / 50);
        $this->logg('Pages count: '.$pages_count);
        // Parse first page
        $this->logg('Parse catalogue page 0');
        $this->parse_catalog_page($arr);

        // Parse rest pages
        for ($page = 1; $page < $pages_count; $page++)
        {
            $json = $this->get_page(str_replace('&pn=0', '&pn='.$page, $url));
            $arr = json_decode($json, true);
            if ($arr == NULL)
            {
                $this->logg('Error in json: '.json_last_error(), 1);
                $this->logg($json);
                continue;
            }
            $this->logg('Parse catalogue page ' . $page);
            $this->parse_catalog_page($arr);
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string $html  [Page HTML]
     */
    private function parse_catalog_page($arr)
    {
        // Get all items
        foreach ($arr['products'] as $item) {
            $this->parse_item($item);
            if ($this->debug) break;
        }

    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($a)
    {
        $this->logg('Parse item details for productID: '.$a['seoIdentifier']);
        $url = $this->apiurl2.$a['seoIdentifier'];
        $json = $this->get_page($url);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 1);
            $this->logg($json);
            return;
        }

        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $this->baseurl.'/shop/'.$this->category.'/'.$this->category_id.'/product/'.$arr['seoUrl'].'/s/'.$arr['seoIdentifier'];

        // Category
        if (preg_match('#\S+-#', $this->category))
            $item['category'] = $this->regex('#(\S+)-#', $this->category, 1);
        else
        {
            $this->logg('No category found: '.$this->category);
            $item['category'] = '';
        }

        // Product type
        if (preg_match('#-\S+#', $this->category))
            $item['product_type'] = $this->regex('#-(\S+)#', $this->category, 1);
        else
        {
            $this->logg('No product type found: '.$this->category);
            $item['product_type'] = '';
        }

        // Brand
        $item['brand'] = $arr['brandName'];
        if ($item['brand'] == '')
        {
            $this->logg('No brand name found', 2);
            $this->logg(json_encode($arr));
            exit();
        }

        // Get all variants
        foreach ($arr['skuVariants'] as $variant)
        {
            if ($variant['quantity'] == 0) continue;    // Skip sold

            // Product name
            $item['product_name'] = $variant['name'];
            if ($item['product_name'] == '')
            {
                $this->logg('No product name found', 2);
                $this->logg(json_encode($arr));
                exit();
            }

            // Product ID
            $item['product_id'] = $variant['skuId'];
            if ($item['product_id'] == '')
            {
                $this->logg('No product ID found', 2);
                $this->logg(json_encode($arr));
                exit();
            }
            // Filter out duplicates by ID
            if ( in_array($item['product_id'], $this->product_ids) )
            {
                $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
                return;
            }
            $this->product_ids[] = $item['product_id'];

            // Price
            $price = $variant['price']['value'];
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

            // Original price
            $original_price = $variant['originalPrice']['value'];
            if ($original_price != '')
            {
                $original_price = $this->regex('#([\d\,\.]+)#', $original_price, 1);
                $item['original_price'] = str_replace(',', '', $original_price);
                $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
            }
            else
            {
                $this->logg('No discount found');
                $item['discount'] = 0;
            }

            // Product option
            $item['product_option'] = trim($variant['attributes']['size']);
            if ($item['product_option'] == '')
            {
                $this->logg('No option found');
                $item['option'] = '';
            }

            // Quantity
            $item['qty_available'] = $arr['quantity'];

            $this->write_item_details($item);
        }
    }

}

$scraper = new CocosaScraper($argv);