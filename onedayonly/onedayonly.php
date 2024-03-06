<?php
/**
 * @file onedayonly.php
 * @brief File contains https://www.onedayonly.co.za website scraper
 */
require_once( '../lib/SaleScraper.php' );

/**
 * @brief Class OneDayOnlyScraper
 * @details Creates a new scraper-instance which extends the main SaleScraper class thus gaining access to all functions
 */
class OneDayOnlyScraper extends SaleScraper
{

    /**
     * @brief Scraper constructor
     * @details Pass site name and currency code and command line arguments to parent class constructor
     * @param array $arg    [Command line arguments]
     */
    public function __construct($arg)
    {
        parent::__construct('OneDayOnly', 'ZAR', $arg);
        $this->set_cookies();
        $this->parse_catalog();
    }

    /**
     * @brief Parse catalog
     * @details Get catalog page HTML from URL, parse and process all pages
     * @param string $url   [Catalog URL]
     * @return bool
     */
    private function parse_catalog()
    {
        $html = $this->get_page($this->baseurl);
		//var_dump($html);
        $this->logg('Parse catalog page');
        $links = array();
        $json = $this->regex('#<script\sid=\"__NEXT_DATA__\"\stype=\"application/json\">([\s\S]+?)</script>#', $html, 1);
        $arr = json_decode($json, true);
        foreach ($arr['props']['pageProps']['homePage']['items'] as $item_node) {
            foreach ($item_node['props']['items'] as $item) {
                if ($item['id'] != '') {
                    $links[] = '/products/'.$item['id'];
                }
            }
        }
        //var_dump($links);
        foreach ($links as $link) {
            $this->parse_item($link);
            if ($this->debug) break;
        }
    }

    /**
     * @brief Get quantity
     * @details Get quantity from HTML
     * @param string $html          [Page HTML]
     * @param string $option_value  [Option value to get quantity]
     * @return int                  [Quantity]
     */
    private function get_qty($html, $option_value)
    {
        $this->logg('Get quantity for option value '.$option_value);
        $json = $this->regex('#var\sco_qtys\s=\s(\{.+\})#', $html, 1);
        if ($json) {
            $qtys = json_decode($json, true);
            foreach ($qtys as $value => $qty) {
                if ($value == $option_value)
                    return $qty;
            }
        }
    }

    /**
     * @brief Find dependents for option
     * @param array $options    [Array of options]
     * @param array $option     [Array of option details]
     * @param array $pids       [Array of PIDs]
     */
    private function find_dependents(&$options, &$option, &$pids)
    {
        $pids[] = $option['id'];
        if (count($option['dependents']) == 0) {
            $index = count($pids) - 1;
            $flag  = true;
            while ($index >= 0) {
                $pid = $pids[$index];
                if ($flag) {
                    foreach ($options[$pid]['dependents'] as $id => $dep) {
                        unset($options[$pid]['dependents'][$id]);
                        break;
                    }
                }
                if (count($options[$pid]['dependents']) == 0) {
                    $flag = true;
                } else
                    $flag = false;
                $index--;
            }
            return;
        }
        $dep = reset($options[$option['id']]['dependents']);
        $res = false;
        foreach ($options as $id => $opt) {
            if ($id == $dep) {
                $this->find_dependents($options, $opt, $pids);
                $res = true;
            }
        }
        if (!$res) {    // No dependents found, probably wrong id, remove
            foreach ($options[$option['id']]['dependents'] as $id => $d)
                if ($d == $dep) unset($options[$option['id']]['dependents'][$id]);
        }
        return;
    }

    /**
     * @brief Parse item
     * @details Get items page HTML, parse item details
     * @param string $url   [Page URL]
     * @return bool
     */
    private function parse_item($url)
    {
        $url = 'https://www.onedayonly.co.za'.$url;
        $html = $this->get_page($url);
        $this->logg('Parse item details from '.$url);
        $this->loadDOM($html);

        // Check if deal expired
        $nodes = $this->xpath->query('//h5[ contains( ., "Deal expired" ) ]');
        if ($nodes->length != 0) {
            $this->logg('Deal is expired', 1);
            return false;
        }

        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['end_date'] = date('Y-m-d');
        $item['product_url'] = $url;

        // Brand
        $item['brand'] = $this->get_node_value('//h2[ @class = "css-y306bx" ]');
        if (!$item['brand']) {
            $this->logg('No brand name found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        // Discount
        $discount = $this->get_node_value('//span[ @class = "css-1kh7h0v" ]');
        if (!$discount)
            $item['discount'] = 0;
        else $item['discount'] = $this->regex('#(\d+)#', $discount, 1);

        // Product name
        $item['product_name'] = $this->get_node_value('//h2[ @class = "css-1cox266" ]');
        if (!$item['product_name']) {
            $this->logg('No product name found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        // Product description
        $item['product_description'] = $this->get_node_value('//h5[ contains(., "About") ]/following-sibling::p');
        if (!$item['product_description']) {
            $this->logg('No product description found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        $item['product_details'] = '';
        // Product details
        foreach ($this->xpath->query('//h5[ contains(., "Product Features") ]/following-sibling::ul/li') as $node) {
            $item['product_details'] .= $node->nodeValue.' ';
        }

        // Category
        $item['category'] = '';
        $item['product_type'] = '';

        // Price
        $price = $this->get_node_value('//h2[ @id = "product-price" ]');
        if ($price) {
            $price = $this->regex('#([\d,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        } else {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Product ID
        $product_id = str_replace('https://www.onedayonly.co.za/products/', '', $url);
        if (!$product_id) {
            $this->logg('No product ID found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        // Get all product variations
        $json = $this->regex('#\<script\sid=\"__NEXT_DATA__\"\stype=\"application/json\">(\{[\s\S]+?\})\<\/script\>#', $html, 1);
        $arr = json_decode($json, true);
        $sp_found = false;
        if (isset($arr['props']['pageProps']['product']))
        {
            // Product image
            if(count($arr['props']['pageProps']['product']['gallery']) > 0) {
                $i = 1;
                foreach ($arr['props']['pageProps']['product']['gallery'] as $gallery) {
                    $item['product_image'.$i] = $gallery['file']['url'];
                    $i++;
                    if ($i > 5) break;
                }
                $item['thumbnail_image'] = $item['product_image1'].'?auto=compress&w=200&h=200&bg=fff&fit=fill';
            }
            if (count($arr['props']['pageProps']['product']['customizableOptions']) > 0) {
                /*foreach ($arr['props']['pageProps']['product']['customizableOptions'][0]['values'] as $prop_name => $prop) {
                    if (!isset($prop['id'])) {
                        continue;
                    }
                    var_dump($prop);
                    $item['product_option'] = $prop['label'];
                    if ($prop['isSoldOut'] == true) {
                        $item['qty_available'] = 0;
                    } else {
                        if ($prop['xLeftQuantity'] != null) {
                            $item['qty_available'] = $prop['xLeftQuantity'];
                        } else {
                            $item['qty_available'] = 10;
                        }
                    }
                    $item['product_id'] = $product_id.'_'.$prop['id'];
                    $this->write_item_details($item);
                }*/
                foreach ($arr['props']['pageProps']['product']['customizableOptions'] as $option) {
                    if ($option['label'] != 'Colour') {
                        $item['product_colour'] = str_replace('Size - ', '', $option['label']);
                        foreach ($option['values'] as $prop_name => $prop) {
                            if (!isset($prop['id'])) {
                                continue;
                            }
                            $item['product_size'] = $prop['label'];
                            $item['product_option'] = $item['product_colour'].' '.$item['product_size'];
                            if ($prop['isSoldOut'] == true) {
                                $item['qty_available'] = 0;
                            } else {
                                if ($prop['xLeftQuantity'] != null) {
                                    $item['qty_available'] = $prop['xLeftQuantity'];
                                } else {
                                    $item['qty_available'] = 10;
                                }
                            }
                            $item['product_id'] = $product_id.'_'.$prop['id'];
                            $this->write_item_details($item);
                        }
                    }
                }
            }
            else {
                $item['product_option'] = '';
                if ($arr['props']['pageProps']['product']['isSoldOut'] == true) {
                    $item['qty_available'] = 0;
                } else {
                    if ($arr['props']['pageProps']['product']['xLeftQuantity'] != null) {
                        $item['qty_available'] = $arr['props']['pageProps']['product']['xLeftQuantity'];
                    } else {
                        $item['qty_available'] = 10;
                    }
                }
                $item['product_id'] = $product_id;
                $this->write_item_details($item);
            }
        }
    }
}

$scraper = new OneDayOnlyScraper($argv);