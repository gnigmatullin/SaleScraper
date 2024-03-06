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
        $this->loadDOM($html);
        $this->logg('Parse catalog page');
        foreach ($this->xpath->query('//a[ @class = "new_product_block" ]') as $node) {
            $link = $node->getAttribute('href');
            $link = 'https://www.onedayonly.co.za/helly-hansen-mens-ullr-midlayer-jacket.html';
            $this->parse_item($link);
            exit();
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
        $html = $this->get_page($url);
        $this->logg('Parse item details from '.$url);
        $this->loadDOM($html);

        // Check if deal expired
        /*$nodes = $this->xpath->query('//h5[ contains( ., "Deal expired" ) ]');
        if ($nodes->length != 0) {
            $this->logg('Deal is expired', 1);
            return false;
        }*/

        $item = [];
        $item['tracking_date'] = date('Y-m-d');
        $item['product_url'] = $url;

        // Brand
        $item['brand'] = $this->get_node_value('//h1[ @itemprop = "name" ]');
        if (!$item['brand']) {
            $this->logg('No brand name found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        // Discount
        $discount = $this->get_node_value('//div[ @id = "save" ]/span');
        if (!$discount)
            $item['discount'] = 0;
        else $item['discount'] = $this->regex('#(\d+)#', $discount, 1);

        // Product name
        $item['product_name'] = $this->get_node_value('//p[ @itemprop = "name" ]');
        if (!$item['product_name']) {
            $this->logg('No product name found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        // Category
        $item['category'] = '';
        $item['product_type'] = '';

        // Price
        $price = $this->get_node_value('//span[ @itemprop = "price" ]');
        if ($price) {
            $price         = $this->regex('#([\d,]+)#', $price, 1);
            $item['price'] = str_replace(',', '', $price);
        } else {
            $this->logg('No price found', 1);
            $item['price'] = 0;
        }

        // Product ID
        $item['product_id'] = $this->get_attribute('//input[ @name = "product" ]', 'value');
        if (!$item['product_id']) {
            $this->logg('No product ID found', 1);
            $this->logg('HTML: '.$html);
            return false;
        }

        // Get product options
        $option_nodes = $this->xpath->query('//option');
        $dep_flag = false;
        foreach ($option_nodes as $option_node) {
            if (!$option_node->hasAttribute('dependents'))
                continue;
            if ($option_node->getAttribute('dependents') == '')
                continue;
            $dep_flag = true;
        }
        if ($dep_flag) {
            $option_nodes = $this->xpath->query('//option');
            $options      = [];
            foreach ($option_nodes as $option_node) {
                if (!$option_node->hasAttribute('id') || !$option_node->hasAttribute('dependents'))
                    continue;
                $option       = [];
                $option['id'] = $option_node->getAttribute('id');
                $dependents   = $option_node->getAttribute('dependents');
                if ($dependents != '') {
                    $option['dependents'] = explode(',', $dependents);
                }
                $option['nodeValue']    = $option_node->nodeValue;
                $option['value']        = $option_node->getAttribute('value');
                $options[$option['id']] = $option;
            }
            if (count($options) > 0) {
                $options2 = $options;
                $i        = 0;
                $res      = true;
                while ($res) {
                    $pids = [];
                    foreach ($options as $option) {
                        if (count($option['dependents']) > 0) {
                            $this->find_dependents($options, $option, $pids);
                            /*echo "options:\r\n";
                            var_dump($options);
                            echo "option:\r\n";
                            var_dump($option);
                            echo "pids:\r\n";
                            var_dump($pids);*/
                            $res = true;
                            break;
                        } else $res = false;
                    }
                    if (!$res)
                        break;
                    $val = '';
                    foreach ($pids as $pid) {
                        $val .= trim(str_replace('(sold out)', '', $options2[$pid]['nodeValue'])).' - ';
                    }
                    // Product option
                    $item['product_option'] = substr($val, 0, -3);
                    // Quantity
                    if (strpos($options2[$pid]['nodeValue'], "sold out") === false)
                        $item['qty_available'] = $this->get_qty($html, $options2[$pid]['value']);
                    else
                        $item['qty_available'] = 0;
                    // Write item
                    $this->logg($pid);
                    $this->write_item_details($item);
                    $i++;
                    if ($i > 20)
                        break; // options limit
                }
            }
        } else    // default option
        {
            $selects = $this->xpath->query('//div[ @id = "selects" ]/select[ contains( @class, "req option" ) ]');
            if ($selects->length == 0) {
                // Quantity
                $nodes = $this->xpath->query('//div[ (@class = "button large") and (contains(., "Sold out!")) ]');
                foreach ($nodes as $node) {
                    $item['qty_available'] = 0;   // sold
                    break;
                }
                if (!isset($item['qty_available']))
                    $item['qty_available'] = 10;
                // Product option
                $item['product_option'] = 'Default';
                // Write item
                $this->write_item_details($item);
            } else {
                if ($selects->length == 1) {
                    foreach ($selects as $select) {
                        $options = $this->xpath->query('./option', $select);
                        $i = 0;
                        foreach ($options as $option) {
                            if ($option->hasAttribute('id')) {
                                // Quantity
                                if (strpos($option->nodeValue, "sold out") === false)
                                    $item['qty_available'] = $this->get_qty($html, $option->getAttribute('value'));
                                else
                                    $item['qty_available'] = 0;
                                // Product option
                                $item['product_option'] = trim(str_replace('(sold out)', '', $option->nodeValue));
                                // Write item
                                $this->write_item_details($item);
                                $i++;
                                if ($i > 20)
                                    break; // options limit
                            }
                        }
                    }
                } else {
                    $i = 0;
                    foreach ($selects as $select) {
                        $nodeValues = [];
                        if ($i == 0) {
                            $options    = $this->xpath->query('./option', $select);
                            $nodeValues = [];
                            foreach ($options as $option) {
                                if ($option->hasAttribute('id')) {
                                    $nodeValues[] = trim(str_replace('(sold out)', '', $option->nodeValue));
                                }
                            }
                        } else {
                            foreach ($nodeValues as $nodeValue) {
                                $options = $this->xpath->query('./option', $select);
                                $j = 0;
                                foreach ($options as $option) {
                                    if ($option->hasAttribute('id')) {
                                        // Quantity
                                        if (strpos($option->nodeValue, "sold out") === false)
                                            $item['qty_available'] = $this->get_qty($html, $option->getAttribute('value'));
                                        else
                                            $item['qty_available'] = 0;
                                        // Product option
                                        $item['product_option'] = $nodeValue.' - '.trim(str_replace('(sold out)', '', $option->nodeValue));
                                        // Write item
                                        $this->write_item_details($item);
                                        $j++;
                                        if ($j > 20)
                                            break; // options limit
                                    }
                                }
                            }
                        }
                        $i++;
                    }
                }
            }
        }
    }
}

$scraper = new OneDayOnlyScraper($argv);