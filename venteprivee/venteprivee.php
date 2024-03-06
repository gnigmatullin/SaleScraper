<?php
/**
 * @file venteprivee.php
 * @brief File contains https://secure.uk.vente-privee.com website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class VentePriveeScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class VentePriveeScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('VentePrivee', 'GBP');
        $this->set_curl_options([
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        $this->set_cookies();
        $this->login();
        //$this->parse_catalogs();
    }

    /**
     * @brief Login on the website
     * @details Pass email and password into post request
     */
    private function login()
    {
        $this->logg('Login to website');
        $url = $this->baseurl.'/authentication/Portal/EN';
        $postfields = [
            'PortalTheme.CountryTheme.CouleurTexte' => 'white',
            'IsFromSecureRequest' => 'False',
            'Email' => 'joeschmoshopper@gmail.com',
            'Password' => '1234!@#$',
            'RememberMe' => 'true',
            'PartnerId' => '14479'
        ];
        $html = $this->post($url, $postfields);
        var_dump($html);
    }

    /**
     * @brief Parse catalogs from entry url
     */
    private function parse_catalogs()
    {
        $options = '{
  "query": "\nquery homes($previewDate: String) {\n  homes(previewDate: $previewDate, tracking: true) {\n    ... on Home {\n      __typename\n      id\n      backgroundTheme {\n        desktop\n      }\n      displayName\n      modules {\n        ... moduleFragment\n      }\n      name\n      notificationBanner {\n        ... bannerFragment\n      }\n      subNavModules {\n        ... subNavModuleFragment\n      }\n      theme\n    }\n    ... on SpecialHome {\n      __typename\n      id\n      backgroundTheme {\n        desktop\n      }\n      displayName\n      categoryTitle\n      modules {\n        ... moduleFragment\n      }\n      name\n      notificationBanner {\n        ... bannerFragment\n      }\n      showAll\n      theme\n    }\n  }\n}\n\n  \n  fragment moduleFragment on Module {\n    ... on SectionBannerModule {\n      __typename\n      id\n      name\n      displayName\n      hideOnSubNavigation\n      isCurrent\n      pictoUrl\n      banners {\n        ... bannerFragment\n      }\n      colors {\n        web {\n          background\n          text\n        }\n      }\n      subtitle\n    }\n    ... on OneDayModule {\n      __typename\n      id\n      name\n      displayName\n      hideOnSubNavigation\n      isCurrent\n      pictoUrl\n      banners {\n        ... bannerFragment\n      }\n      colors {\n        web {\n          background\n          text\n        }\n      }\n      subtitle\n    }\n    ... on SectionPaginatedBannerModule {\n      __typename\n      banners {\n        ... bannerFragment\n      }\n      colors {\n        web {\n          background\n          text\n        }\n      }\n      displayName\n      hideOnSubNavigation\n      id\n      isCurrent\n      name\n      pagination {\n        desktopBannersList\n        maxPagination\n        numberBannersPerPage\n        numberOfBannersOnFirstPage\n        showButtonNewsletter\n        wordingLoadMore\n        wordingLoadMoreButton\n      }\n      pictoUrl\n      subtitle\n    }\n    ... on TagCategoriesModule {\n      __typename\n      banners {\n        ... bannerFragment\n      }\n      colors {\n        web {\n          background\n          text\n        }\n      }\n      displayName\n      id\n      name\n      subtitle\n    }\n    ... on CategoriesModule {\n      __typename\n      banners {\n        ... bannerFragment\n      }\n      colors {\n        web {\n          background\n          text\n        }\n      }\n      displayName\n      id\n      name\n      subtitle\n    }\n    ... on HighlightModule {\n      __typename\n      banners {\n        ... bannerFragment\n      }\n      displayName\n      id\n      name\n      colors {\n        web {\n          background\n          text\n        }\n      }\n      gradient {\n        end\n        start\n      }\n      subtitle\n    }\n  }\n\n  \n  fragment bannerFragment on Banner {\n    __typename\n    id\n    name\n    image {\n      size\n      url\n    }\n    placeholder\n    ... saleBannerFragment\n    ... notificationBannerFragment\n    ... campaignBannerFragment\n    ... categoryBannerFragment\n    ... highlightBannerFragment\n  }\n\n  fragment saleBannerFragment on Banner {\n    ... on SaleBanner {\n      businessUnit\n      beginDate\n      categories\n      category\n      description\n      endDate\n      externalLink\n      filter {\n        categories\n        dates\n      }\n      infoBaseLine\n      isBrandAlert\n      mediaType\n      mediaUrls {\n        logoImage\n        ... on ClassicMediaUrls {\n          ambianceImage\n        }\n        ... on OneDayMediaUrls {\n          carrouselImage\n          catalogImage\n        }\n      }\n      operationCode\n      saleBusinessId\n      saleSectorId\n      saleSubSectorId\n      saleTypeId\n      shareable\n      shouldShowInfo\n      showBusinessLogo\n      siteTrailer\n    }\n  }\n\n  fragment notificationBannerFragment on Banner {\n    ... on NotificationBanner {\n      beginDate\n      buttonLabel\n      displayName\n      endDate\n      redirect {\n        link\n        target\n        template\n      }\n      logoUrl\n    }\n  }\n\n  fragment campaignBannerFragment on Banner {\n    ... on CampaignBanner {\n      businessUnit\n      redirect {\n        link\n        target\n        template\n      }\n      operationCode\n      filter {\n        categories\n        dates\n      }\n    }\n  }\n\n  fragment categoryBannerFragment on Banner {\n    ... on CategoryBanner {\n      redirect {\n        link\n        target\n        template\n      }\n    }\n  }\n\n  fragment highlightBannerFragment on Banner {\n    ... on HighlightBanner {\n      beginDate\n      businessUnit\n      description\n      endDate\n      id\n      image {\n        url\n      }\n      name\n      redirect {\n        link\n        target\n        template\n      }\n    }\n  }\n\n  \nfragment subNavModuleFragment on SubNavModule {\n  __typename\n  showAll\n  title\n  ... subNavCategoryModuleFragment\n  ... subNavVPassModuleFragment\n  ... subNavSaleModuleFragment\n}\n\nfragment subNavCategoryModuleFragment on SubNavModule {\n  ... on SubNavCategoryModule {\n    items {\n      ... subNavModuleItemFragment\n    }\n  }\n}\nfragment subNavVPassModuleFragment on SubNavModule {\n  ... on SubNavVPassModule {\n    id\n    items {\n      ... subNavModuleItemFragment\n    }\n  }\n}\nfragment subNavSaleModuleFragment on SubNavModule {\n  ... on SubNavSaleModule {\n    items {\n      ... subNavModuleItemFragment\n    }\n  }\n}\n\nfragment subNavModuleItemFragment on SubNavModuleItem {\n  id\n  linkUrl\n  name\n  ... on Category {\n    hasItems\n    defaultName\n    mediaUrls {\n      v2\n      v3\n    }\n  }\n}\n\n",
  "variables": {}
}';
        $this->set_curl_options([CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $json = $this->post2('https://www.veepee.uk/ns-sd/frontservices/navigation/graphql', $options);
        var_dump($json);
        exit();
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @return bool
     */
    private function parse_catalog()
    {
        $this->logg('Parse catalog');
        $this->set_curl_options([CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $json = $this->get_page('/ns-sd/frontservices/navigation/graphql');
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 2);
            $this->logg($json);
            exit();
        }
        if (!isset($arr['datas']['homes']))
        {
            $this->logg('No sales found', 2);
            $this->logg($json);
            exit();
        }
        // Get all sales
        foreach ($arr['datas']['homes'] as $home)
        {
            if ($home['displayName'] != 'Fashion') continue;
            foreach ($home['homeParts'] as $part)
            {
                if ($part['displayName'] != 'CURRENT SALES') continue;
                foreach ($part['banners'] as $banner_id) {
                    $this->parse_sale($banner_id);
                    if ($this->debug == 2) break;
                }
            }
        }
    }

    /**
     * @brief Parse catalog page
     * @details Parse and process all items pages from catalog HTML
     * @param string saleId  [Sale ID for parse]
     */
    private function parse_sale($saleId)
    {
        $this->category_id = $saleId;
        $this->logg('Parse sale: '.$saleId);
        $json = $this->get_page('/ns-sd/frontservices/2.0/operation/enteroperationwithoutparticipation/'.$saleId);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 2);
            $this->logg($json);
            exit();
        }
        if (!isset($arr['datas']['homeUniverseId']) || $arr['datas']['homeUniverseId'] == 0)
        {
            $this->logg('No homeUniverseId found', 2);
            $this->logg($json);
            exit();
        }
        $this->logg('homeUniverseId: '.$arr['datas']['homeUniverseId']);
        $this->category = $arr['datas']['fullName'];
        if ($this->category == '')
        {
            $this->logg('No category name found', 1);
        }
        $this->end_date = $arr['datas']['endDate'];
        if ($this->end_date == '')
        {
            $this->logg('No end date found', 1);
        }
        $json = $this->get_page('/ns-sd/frontservices/2.0/salespace/getsalespacecontentbyuniverse/10/'.$arr['datas']['homeUniverseId'].'/false?sfq=');
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 2);
            $this->logg($json);
            exit();
        }
        if (!isset($arr['datas']['productFamilies']))
        {
            $this->logg('No items found', 2);
            $this->logg($json);
            exit();
        }
        $this->logg('Parse items');
        // Get all items
        foreach ($arr['datas']['productFamilies'] as $item) {
            $this->parse_item($item);
            if ($this->debug == 2) break;
        }

    }

    /**
     * @brief Parse item
     * @details Parse item details from array
     * @param array $arr
     * @return bool
     */
    private function parse_item($arr)
    {
        $this->logg('Parse item details for productID: '.$arr['id']);
        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $this->baseurl.'/ns/en-gb/operation/'.$this->category_id.'/classic/product-sheet/'.$arr['id'];
        $item['category'] = $this->category;
        $item['end_date'] = $this->end_date;

        // Product type
        $item['product_type'] = $arr['breadCrumbItems'][0]['title'];
        if ($item['product_type'] == '')
        {
            $this->logg('No product type found', 1);
        }

        // Brand
        $item['brand'] = $arr['saleName'];
        if ($item['brand'] == '')
        {
            $this->logg('No brand name found', 2);
            $this->logg(json_encode($arr));
            exit();
        }

        // Get all quantities
        $quantities = $this->parse_quantities($arr['id']);
        
        // Get all variants
        foreach ($arr['products'] as $variant)
        {
            // Product name
            $item['product_name'] = $variant['designation'];
            if ($item['product_name'] == '')
            {
                $this->logg('No product name found', 2);
                $this->logg(json_encode($arr));
                exit();
            }

            // Product ID
            $item['product_id'] = $variant['id'];
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
            $price = $variant['price'];
            if ($price != '')
            {
                $price = $this->regex('#([\d\,\.]+)#', $price, 1);
                $item['price'] = str_replace(',', '', $price);
            }
            else
            {
                $this->logg('No price found', 1);
                $item['price'] = 0;
            }

            // Original price
            $original_price = $variant['retailPrice'];
            if (($original_price != '') && ($original_price != 0))
            {
                $original_price = $this->regex('#([\d\,\.]+)#', $original_price, 1);
                $item['original_price'] = str_replace(',', '', $original_price);
                $item['discount'] = ceil(($item['original_price'] - $item['price']) / $item['original_price'] * 100);
            }
            else
            {
                $this->logg('No discount found', 1);
                $item['discount'] = 0;
            }

            // Option
            $item['product_option'] = $variant['size'];
            if ($item['product_option'] == '')
            {
                $this->logg('No product option found', 1);
                $item['product_option'] = '';
            }

            // Quantity
            if (isset($quantities[$item['product_id']]))
                $item['qty_available'] = $quantities[$item['product_id']];

            if ($item['qty_available'] > 0)
                $this->write_item_details($item);
        }
    }

    /**
     * @brief Parse quantities for item
     * @param string $itemId    [Item ID for parse]
     * @return array            [Array of quantities]
     */
    private function parse_quantities($itemId)
    {
        $this->logg('Parse quantities for item: '.$itemId);
        $json = $this->get_page('/ns-sd/frontservices/2.1/stock/getstockbyproductfamily/'.$itemId);
        $arr = json_decode($json, true);
        if ($arr == NULL)
        {
            $this->logg('Error in json: '.json_last_error(), 2);
            $this->logg($json);
            exit();
        }
        if (!isset($arr['datas']))
        {
            $this->logg('No quantities found', 2);
            $this->logg($json);
            exit();
        }
        $quantities = [];
        foreach ($arr['datas'] as $id => $data)
        {
            $this->logg('Variant ID: '.$id.' quantity: '.$data['stock']);
            $quantities[$id] = $data['stock'];
        }
        return $quantities;
    }

}

$scraper = new VentePriveeScraper($argv);