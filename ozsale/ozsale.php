<?php
/**
 * @file ozsale.php
 * @brief File contains https://www.ozsale.com.au website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class OzSaleScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class OzSaleScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('OzSale', 'AUD', $arg);
        $this->parse_categories();
    }

    /**
     * @brief Parse categories
     * @details Run parse_catalog function for each category
     */
    private function parse_categories()
    {
        $this->logg('Parse catalogue');
        $json = $this->get_page($this->baseurl.'/ApacHandlers.ashx/GetPublicSalesBanners?saleCategoryID=40f80218-a9e1-43c4-96ff-4c046d192a21&topSalesCount=3&useOzsaleSize=true&getPromotion=true&groupNo=&languageID=en&countryID=AS&userGroup=');
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error(), 2);
            $this->logg($json);
            return;
        }
        // Get all sales
        foreach ($arr['d']['List'] as $list) {
            foreach ($list['Sales'] as $sale) {
                $this->parse_catalog($sale['ID']);
            }
        }
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog($saleID)
    {
        $this->saleID = $saleID;
        $this->logg('Get items from sale: '.$saleID);
        $json = $this->get_page($this->baseurl.'/ApacHandlers.ashx/GetPublicSaleItems?saleID='.$saleID.'&imageSize=100&languageID=en&countryID=AS&userGroup=');
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in JSON: '.json_last_error(), 2);
            $this->logg($json);
            return;
        }
        // Get all items
        $i = 0;
        foreach ($arr['d']['List'] as $list)
        {
            $this->category = $list['Name'];
            foreach ($list['SubCategories'] as $subcategory)
            {
                $this->product_type = $subcategory['Name'];
                foreach ($subcategory['Items'] as $item)
                {
                    $this->parse_item($item);
                    $i++;
                }
            }
        }
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($arr)
    {
        $this->logg('Parse item details for productID: '.$arr['ID']);
        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $this->baseurl.'/product.aspx?cid=10&saleID='.$this->saleID.'&productID='.$arr['ID'];
        $item['category'] = $this->category;
        $item['product_type'] = $this->product_type;
        // Brand
        $item['brand'] = $arr['BrandName'];
        if ($item['brand'] == '')
        {
            $this->logg('No brand name found', 2);
            $this->logg(json_encode($arr));
            return;
        }
        // Product name
        $item['product_name'] = $arr['Name'];
        if ($item['product_name'] == '')
        {
            $this->logg('No product name found', 2);
            $this->logg(json_encode($arr));
            return;
        }
        // Product ID
        $item['product_id'] = $arr['ID'];
        if ($item['product_id'] == '')
        {
            $this->logg('No product ID', 2);
            $this->logg(json_encode($arr));
            return;
        }
        // Filter out duplicates by ID
        if ( in_array($item['product_id'], $this->product_ids) )
        {
            $this->logg('Product ID '.$item['product_id']. ' already scraped', 1);
            return;
        }
        $this->product_ids[] = $item['product_id'];
        // Price
        $price = $arr['Price'];
        if ($price != '')
        {
            $price = $this->regex('#([\d,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }
        // RP
        $rp = $arr['RP'];
        if ($rp != '')
        {
            $rp = $this->regex('#([\d,]+)#', $rp, 1);
            $item['rp'] = str_replace(',', '', $rp);
            // Discount
            $item['discount'] = ceil(($item['rp'] - $item['price']) / $item['rp'] * 100);
        }
        else
        {
            $this->logg('No price found', 1);
            $item['discount'] = 0;
        }
        // Product option
        $item['product_option'] = '';
        // Quantity
        $item['qty_available'] = 0;
        $this->write_item_details($item);
    }
}

$scraper = new OzSaleScraper($argv);