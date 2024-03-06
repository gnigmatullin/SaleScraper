<?php
require_once 'simplexlsx.class.php';
require 'vendor/autoload.php';
use Aws\S3\S3Client;

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

$scraper_log = "puma.log";

// Write log
function logg($text)
{
    global $scraper_log;
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents($scraper_log, $log , FILE_APPEND | LOCK_EX);
}

// Run regexp on a string and return the result
function regex( $regex, $input, $output = 0 )
{
    $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
    if ( ! $match )
        $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
    return $match;
}

class Puma
{
    var $key;       // Auth key
    var $referer;
    var $item;      // Item details
    var $pd_key;    // Catalogue ID
    var $ean_codes;
    var $active_color;
    var $group;
    var $type;
    var $gender;
    var $sub_category;
    var $category;
    var $csv_file;
    var $csv_header = [
        'ID',
        'Style No',
        'Article No',
        'Product Name',
        'Colour',
        'Group',
        'Gender',
        'Type',
        'Category',
        'Sub-Category',
        'EAN',
        'WSP',
        'COP',
        'RRP',
        'Size',
        'Qty',
        'Image',
        'Image 2',
        'Image 3'
    ];
    var $csv_log = "puma_log.csv";
    var $csv_log_header = [
        'Scrape Date',
        'Scrape Time',
        'Scrape Duration',
        'Scrape Result',
        'Total Products',
        'Total Styles',
        'Total Articles',
        'Total Products with Qty',
        'Total Styles with Qty',
        'Total Articles with Qty',
        'Total Qty'
    ];
    var $images = [];
    var $scrape_result = false;
    var $total_products = [];
    var $total_styles = [];
    var $total_articles = [];
    var $total_products_qty = [];
    var $total_styles_qty = [];
    var $total_articles_qty = [];
    var $sum_qty = 0;
    
    function Puma()
    {
        $this->csv_file = "./input_new/Puma_B2B_" . @date('Ymd_hi') . ".csv";
        logg('--------------------');
        logg('Puma Scraper started');   
        $start_date = @date('Y-m-d');
        $start_time = @date('H:i');
        $start_microtime = microtime(true);
        // Create csv and put header
        $csv_handle = fopen($this->csv_file, "w");
        fputcsv($csv_handle, $this->csv_header);
        fclose($csv_handle);
        
        $this->login();
        $html = $this->get_main_page();
        $this->parse_main_page($html);
        $this->logout();

        // Upload results to Amazon S3
        $client = new S3Client([
            'version' => 'latest',
            'region'  => 'eu-west-1',
            'credentials' => [
                'key'    => '',
                'secret' => ''
            ]
        ]);

        logg("Upload output file {$this->csv_file} to S3");
        $client->putObject(array(
            'Bucket' => 'rws-portal-global',
            'Key'    => 'puma' . str_replace( './', '/', $this->csv_file ),
            'Body'   => fopen($this->csv_file, 'r+')
        ));
        
        logg("Download log file {$this->csv_log} from S3");
        $result = $client->getObject(array(
            'Bucket' => 'rws-portal-global',
            'Key'    => 'puma/' . $this->csv_log,
            'SaveAs' => $this->csv_log
        ));
        
        logg("Write to csv log");
        $csv_log_handle = fopen($this->csv_log, "a");
        if ($this->scrape_result) $result_str = 'Success';
        else $result_str = 'Failed';
        $elapsed_time = ceil( microtime(true) - $start_microtime );
        $scrape_duration = gmdate("H:i:s", $elapsed_time);
        $total_products = count($this->total_products);
        $total_styles = count($this->total_styles);
        $total_articles = count($this->total_articles);
        $total_products_qty = count($this->total_products_qty);
        $total_styles_qty = count($this->total_styles_qty);
        $total_articles_qty = count($this->total_articles_qty);
        $log_line = array(
            $start_date,
            $start_time,
            $scrape_duration,
            $result_str,
            $total_products,
            $total_styles,
            $total_articles,
            $total_products_qty,
            $total_styles_qty,
            $total_articles_qty,
            $this->sum_qty
        );
        fputcsv($csv_log_handle, $log_line);
        fclose($csv_log_handle);

        logg("Upload log file {$this->csv_log} to S3");
        $client->putObject(array(
            'Bucket' => 'rws-portal-global',
            'Key'    => 'puma/' . $this->csv_log,
            'Body'   => fopen($this->csv_log, 'r+')
        ));
        logg("-------");
        logg("Summary");
        logg("Scrape Date: $start_date");
        logg("Scrape Time: $start_time");
        logg("Scrape Duration: $scrape_duration");
        logg("Scrape Result: $result_str");
        logg("Total Products Count: $total_products");
        logg("Total Styles Count: $total_styles");
        logg("Total Articles Count: $total_articles");
        logg("Total Products with Qty Count: $total_products_qty");
        logg("Total Styles with Qty Count: $total_styles_qty");
        logg("Total Articles with Qty Count: $total_articles_qty");
        logg("Total Qty: {$this->sum_qty}");
        logg("Elapsed time $elapsed_time s");
        logg("Scraper stopped");
    }
    
    function get_page($url)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30
        );
        logg("Get page $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            logg("Response code ".$info['http_code']);
            //var_dump ($info);
        }
        else
        {
            $err = curl_error( $ch );
            logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
        return $html;
    }
    
    // Login to website and save key
    function login()
    {
        $html = $this->get_page('https://www.puma-b2b.com/za');
        if (!preg_match('#name=\"key\"\svalue=\"[\w\d]+\"#', $html))
        {
            logg('No auth key found');
            return false;
        }
        $this->key = regex('#name=\"key\"\svalue=\"([\w\d]+)\"#', $html, 1);
        logg('Auth key: '.$this->key);
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'login=laura.p@runwaysale.co.za&password=RunWaySale123456&button_login=login&original_shop=abd815286ba1007abfbb8415b83ae2cf&key='.$this->key
        );
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/login.ipm';
        logg("Login to $url");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            logg("Response code ".$info['http_code']);
            //var_dump ($info);
        }
        else
        {
            $err = curl_error( $ch );
            logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
    }
    
    function logout()
    {
        logg('Logout');
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/logout.ipm';
        $this->get_page($url);
    }
    
    function get_main_page()
    {
        logg('Get main page');
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/content/documentPage.ipm?document=d32714&amp;url_stack_id_prev=-1';
        $html = $this->get_page($url);
        return $html;
    }
    
    function parse_main_page($html)
    {
        logg('Parse main page from html');
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $x = new DOMXPath($dom);
        // Get catalogues
        $nodes = $x->query('//ul[ contains( @class, "puma_shop__navigation_list" ) ]');
        $ean = false;
        foreach ( $nodes as $node )
        {
            $links = $x->query('./li[ contains( @class, "puma_shop__navigation_list_item" ) ]/a', $node);
            foreach ( $links as $link )
            {
                $href = $link->getAttribute('href');
                $this->pd_key = regex('#pd_key=(\d+)#', $href, 1);
                //var_dump($this->pd_key);
                $page = 1;
                while (1)
                {
                    $html = $this->get_catalogue_page($page);
                    if (!$ean)  // Get EAN only at first call
                    {
                        $this->get_ean_codes();
                        $ean = true;
                    }
                    if ( !$this->parse_catalogue_page($html) ) break;
                    $page++;
                }
            }
            break;
        }
    }
    
    function get_catalogue_page($page)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'pd_key='.$this->pd_key.'&engine_hidden_fields_begin=y&find_use_multiselect=n&s_quick_items_per_page=24&s_quick_sort_order=rank&slave=n&s_quick_mode=search&url_stack_id=3&search_stack_init_mode=DEFAULT&search_stack_id=2&store_search_variables=y&rpage='.$page.'&page=1&search_form_completed=y&use_last_search_fields=1&taid=-1&search_dataloader_mode=block_list&search_dataloader_block_list=result_page_contents__list_elements%01%02&search=quick&search_dataloader_use_generic_action=y&button_rpage=rpage'
        );
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/search/main.ipm';
        logg("Get catalogue: {$this->pd_key} page: $page");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            logg("Response code ".$info['http_code']);
            //var_dump ($info);
        }
        else
        {
            $err = curl_error( $ch );
            logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
        return $html;
    }
    
    function parse_catalogue_page($html)
    {
        logg('Parse catalogue page from html');
        $last_page = false;
        preg_match_all('#data-ip_infinite_scroll_remaining_results=\\\"(\d+)\\\"#', $html, $matches);
        foreach ( array_unique($matches[1]) as $remain )
        {
            if ($remain <= 0) $last_page = true;
        }
        preg_match_all('#object_id=(\d+)#', $html, $matches);   
        if ( count($matches[1]) == 0 )
        {
            logg('No items found');
            return false;
        }
        foreach ( array_unique($matches[1]) as $object_id )
        {
            $item_html = $this->get_item_main_page($object_id);
            $styles = $this->parse_item_main_page($item_html);
            foreach ( $styles as $style )
            {
                $item_html = $this->get_item_page($object_id, $style);
                $this->parse_item_page($object_id, $item_html);
            }
        }
        if (!$last_page) return true;
        else
        {
            logg('Last page found');
            return false;
        }
    }
    
    // Get item page
    function get_item_main_page($object_id)
    {
        logg('Get main item page: '.$object_id);
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/objectView/main.ipm?obj=catalogArticle&object_id='.$object_id;
        $this->referer = $url;
        $html = $this->get_page($url);
        //var_dump($html);
        return $html;
    }
    
    // Parse all styles for item
    function parse_item_main_page($html)
    {
        logg('Parse item main page');
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $x = new DOMXPath($dom);
        // Parse active color
        $this->active_color = '';
        $nodes = $x->query('//input[ @name = "active_color_value_key" ]');
        foreach ( $nodes as $node )
        {
            $this->active_color = $node->getAttribute('value');
            break;
        }
        // Parse group
        $this->group = '';
        $nodes = $x->query('//span[ contains( @class, "puma_style_color_flag" ) ]');
        foreach ( $nodes as $node )
        {
            $this->group = $node->nodeValue;
            break;
        }
        // Parse type, subcategory, gender, category
        $this->type = '';
        $this->sub_category = '';
        $this->gender = '';
        $this->category = '';
        $nodes = $x->query('//ul[ @class = "top_navigation_breadcrumb__normal clearfix" ]/li');
        $i = 0;
        foreach ( $nodes as $node )
        {
            switch ($i)
            {
                case 1: $this->type = trim($node->nodeValue);
                    break;
                case 2: $this->sub_category = trim($node->nodeValue);
                    break;
                case 3: $this->gender = trim($node->nodeValue);
                    break;
                case 4: $this->category = trim($node->nodeValue);
                    break;
            }
            $i++;
        }
        logg('Acitve color: '.$this->active_color.' Group: '.$this->group.' Sub category: '.$this->sub_category.' Gender: '.$this->gender.' Category: '.$this->category);
        // Parse styles
        $nodes = $x->query('//span[ @class = "feedviewer__content_list_item_name" ]/strong');
        $styles = [];
        foreach ( $nodes as $node )
        {
            $styles[] = $node->nodeValue;
            $dump .= $node->nodeValue . ' ';
        }
        logg('Styles: '.$dump);
        return $styles;
    }
    
    // Get item page for specified style
    function get_item_page($object_id, $style)
    {
        logg('Get item: '.$object_id.' style: '.$style);
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_REFERER => $this->referer,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'active_color_value_key='.$this->active_color.'&object_id='.$object_id.'&obj=catalogArticle&url_stack_id_prev=1&search_stack_id=1&page=customer&objectView_form_completed=y&taid=-1&objectView_dataloader_mode=block_list&objectView_dataloader_block_list=ov_product_details%01%02&objectView_dataloader_use_generic_action=y&button_set_active_color_value_key'.$style.'=set_active_color_value_key'.$style
        );
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/objectView/main.ipm';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            logg("Response code ".$info['http_code']);
            //var_dump ($info);
        }
        else
        {
            $err = curl_error( $ch );
            logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
        return $html;
    }
    
    // Parse item details
    function parse_item_page($object_id, $html)
    {
        logg('Parse item details from html');
        $json = regex('#({[\s\S]+})#', $html, 1);
        $arr = json_decode($json, true);
        $html = $arr['response_content'][0]['content'];
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $x = new DOMXPath($dom);
        $this->item = [];
        $this->item['id'] = $object_id;
        $nodes = $x->query('//dt[ contains(., "Article Number:") ]/following-sibling::dd');
        foreach ( $nodes as $node )
        {
            $article_number = $node->nodeValue;
            $this->item['style_no'] = regex('#(\d+)$#', $article_number, 1);
            $this->item['style_no'] = sprintf('%02d', $this->item['style_no']);
            $this->item['article_no'] = regex('#^(\d+)#', $article_number, 1);
            break;
        }
        if (!isset($this->item['style_no'])) $this->item['style_no'] = '';
        if (!isset($this->item['article_no'])) $this->item['article_no'] = '';
        $nodes = $x->query('//h1');
        foreach ( $nodes as $node )
        {
            $this->item['product_name'] = $node->nodeValue;
            break;
        }
        if (!isset($this->item['product_name'])) $this->item['product_name'] = '';
        $nodes = $x->query('//dt[ contains(., "Color:") ]/following-sibling::dd');
        foreach ( $nodes as $node )
        {
            $this->item['colour'] = $node->nodeValue;
            break;
        }
        if (!isset($this->item['colour'])) $this->item['colour'] = '';

        $this->item['group'] = $this->group;
        $this->item['gender'] = $this->gender;
        $this->item['type'] = $this->type;
        $this->item['category'] = $this->category;
        $this->item['sub_category'] = $this->sub_category;

        $nodes = $x->query('//dt[ contains(., "WSP List from") ]/following-sibling::dd');
        foreach ( $nodes as $node )
        {
            $this->item['wsp'] = htmlspecialchars_decode($node->nodeValue);
            $this->item['wsp'] = preg_replace('#\,\d+#', '', $this->item['wsp']);
            $this->item['wsp'] = preg_replace('#[^\d]#', '', $this->item['wsp']);
            break;
        }
        if (!isset($this->item['wsp'])) $this->item['wsp'] = '';
        $nodes = $x->query('//dd[ @class = "ov_ca__style_information__price_list_definition ov_ca__style_information__price_cop" ]');
        foreach ( $nodes as $node )
        {
            $this->item['cop'] = htmlspecialchars_decode($node->nodeValue);
            $this->item['cop'] = preg_replace('#\,\d+#', '', $this->item['cop']);
            $this->item['cop'] = preg_replace('#[^\d]#', '', $this->item['cop']);
            break;
        }
        if (!isset($this->item['cop'])) $this->item['cop'] = '';
        $nodes = $x->query('//dt[ contains(., "RRP from") ]/following-sibling::dd');
        foreach ( $nodes as $node )
        {
            $this->item['rrp'] = htmlspecialchars_decode($node->nodeValue);
            $this->item['rrp'] = preg_replace('#\,\d+#', '', $this->item['rrp']);
            $this->item['rrp'] = preg_replace('#[^\d]#', '', $this->item['rrp']);
            break;
        }
        if (!isset($this->item['rrp'])) $this->item['rrp'] = '';
        // Get images
        $this->images = [];
        $nodes = $x->query('//img[ @class = "product_image_thumbnail_cart" ]');
        foreach ( $nodes as $node )
        {
            $this->images[] = 'https://www.puma-b2b.com'.str_replace('image_thumbnail_cart', 'image_detail_big', $node->getAttribute('src'));
        }
        if (count($this->images) == 0)
        {
            $nodes = $x->query('//img[ @class = "product_image_detail_small" ]');
            foreach ( $nodes as $node )
            {
                $this->images[] = 'https://www.puma-b2b.com'.str_replace('image_detail_small', 'image_detail_big', $node->getAttribute('src'));
            }
        }
        // Get sizes
        $html = $this->get_sizes($object_id);
        $this->parse_sizes_page($html);
    }
    
    function get_sizes($object_id)
    {
        $options = array (
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_REFERER => $this->referer,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'active_color_value_key='.$this->active_color.'&item_position_in_search_hits=1&object_id='.$object_id.'&obj=catalogArticle&url_stack_id_prev=1&search_stack_id=1&page=customer&objectView_form_completed=y&taid=-1&objectView_dataloader_mode=block_list&objectView_dataloader_block_list=ov_product_details%01%02&objectView_dataloader_use_generic_action=y&button_add_all_size_value_keys=add_all_size_value_keys'
        );
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/objectView/main.ipm';
        logg("Get all sizes for object $object_id");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        //var_dump($html);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            logg("Response code ".$info['http_code']);
            //var_dump ($info);
        }
        else
        {
            $err = curl_error( $ch );
            logg("CURL error ".curl_errno($ch)." ".$err);
            return -1;
        }
        curl_close($ch);
        return $html;
    }
    
    function parse_sizes_page($html)
    {
        logg('Parse sizes from html');
        $json = regex('#({[\s\S]+})#', $html, 1);
        $arr = json_decode($json, true);
        $html = $arr['response_content'][1]['content'];
        //var_dump($html);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $x = new DOMXPath($dom);
        $nodes = $x->query('//table[ @class = "ov_ca__order_position_table" ]/tbody/tr');
        foreach ( $nodes as $node )
        {
            $size_nodes = $x->query('./td/div[ @class = "ov_ca__order_position__size" ]/a/span', $node);
            foreach ($size_nodes as $size_node)
            {
                $this->item['size'] = $size_node->nodeValue;
                break;
            }
            $qty_nodes = $x->query('./td/div[ @class = "ov_ca__order_position__availability" ]/p', $node);
            foreach ($qty_nodes as $qty_node)
            {
                $this->item['qty'] = $qty_node->nodeValue;
                break;
            }
            // Add images
            for ( $i = 0; $i <= 2; $i++ )
            {
                if ( isset($this->images[$i]) )
                    $this->item['image'.$i] = $this->images[$i];
                else
                    $this->item['image'.$i] = '';
            }
            // Add ean code
            $this->item['ean'] = $this->ean_codes[$this->item['article_no'].$this->item['style_no'].$this->item['size']];
            $this->write_item_details();
        }
    }
    
    function write_item_details()
    {
        // Counters for log
        if ( !in_array($this->item['id'], $this->total_products) )
            $this->total_products[] = $this->item['id'];
        if ( ( $this->item['qty'] > 0 ) && ( !in_array($this->item['id'], $this->total_products_qty) ) )
            $this->total_products_qty[] = $this->item['id'];
        if ( !in_array($this->item['style_no'], $this->total_styles) )
            $this->total_styles[] = $this->item['style_no'];
        if ( ( $this->item['qty'] > 0 ) && ( !in_array($this->item['style_no'], $this->total_styles_qty) ) )
            $this->total_styles_qty[] = $this->item['style_no'];        
        if ( !in_array($this->item['article_no'], $this->total_articles) )
            $this->total_articles[] = $this->item['article_no'];
        if ( ( $this->item['qty'] > 0 ) && ( !in_array($this->item['article_no'], $this->total_articles_qty) ) )
            $this->total_articles_qty[] = $this->item['article_no'];
        $this->sum_qty++;
        
        // Regroup item array
        $item = [];
        $item['id'] = $this->item['id'];
        $item['style_no'] = $this->item['style_no'];
        $item['article_no'] = $this->item['article_no'];
        $item['product_name'] = $this->item['product_name'];
        $item['colour'] = $this->item['colour'];
        $item['group'] = $this->item['group'];
        $item['gender'] = $this->item['gender'];
        $item['type'] = $this->item['type'];
        $item['category'] = $this->item['category'];
        $item['sub_category'] = $this->item['sub_category'];
        $item['ean'] = $this->item['ean'];
        $item['wsp'] = $this->item['wsp'];
        $item['cop'] = $this->item['cop'];
        $item['rrp'] = $this->item['rrp'];
        $item['size'] = $this->item['size'];
        $item['qty'] = $this->item['qty'];
        $item['image0'] = $this->item['image0'];
        $item['image1'] = $this->item['image1'];
        $item['image2'] = $this->item['image2'];
        
        $dump = 'Write item details into csv ( ';
        foreach ( $item as $key => $value )
            $dump .= $key . ': ' . $value . ' ';
        $dump .= ' )';
        logg($dump);
        $csv_handle = fopen($this->csv_file, "a");
        fputcsv($csv_handle, $item);
        fclose($csv_handle);
        
        $this->scrape_result = true;
    }
    
    // Get EAN codes and save to xlsx file
    function get_ean_codes()
    {
        logg('Get EAN codes');
        $url = 'https://www.puma-b2b.com/za/key='.$this->key.'/PumaFront/jsRequestDispatcher.ipm?excel_type=simple&action=createExcelFile';
        $html = $this->get_page($url);
        $arr = json_decode($html, true);
        $href = regex('#href=\"(.+?)\"#', $arr['content'], 1);
        $href = htmlspecialchars_decode($href);
        if ( $href == '' )
        {
            logg('Wrong EAN codes url');
            return false;
        }
        logg('Download xlsx file: '.$href);
        $content = file_get_contents($href);
        file_put_contents('ean.xlsx', $content);
        $this->read_ean_codes();
    }
    
    // Read EAN codes from xlsx
    function read_ean_codes()
    {
        logg('Read EAN codes from xlsx');
        $this->ean_codes = [];
        if ( $xlsx = SimpleXLSX::parse('ean.xlsx') )
        {
            foreach ($xlsx->rows() as $row)
            {
                $this->ean_codes[$row[0].$row[1].$row[4]] = $row[5];
            }
        } 
        else
        {
            logg('XLSX read error: '.SimpleXLSX::parse_error());
        }
        //var_dump($this->ean_codes);
        if ( count($this->ean_codes) > 0 )
        {
            logg(count($this->ean_codes).' EAN codes read');
            return true;
        }
        else
        {
            logg('No EAN codes found');
            return false;
        }
    }

}

// Create new log
$log_handle = fopen($scraper_log, "w");
fclose($log_handle);

$puma = new Puma();

?>