<?php
require 'vendor/autoload.php';
use Aws\S3\S3Client;

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

$scraper_log = "asicsonline.log";
$scraper_csv_log = "asics_log.csv";
$csv_log_header = ['Scrape Date', 'Scrape Time', 'Scrape Duration', 'Scrape Result', 'Total Products', 'Total Styles', 'Total SKUs', 'Total Products with Qty', 'Total Styles with Qty', 'Total SKUs with Qty', 'Total SKU Qty'];
$cookies_file = "asicsonline_cookies.txt";
$base_url = "http://b2b.asicsonline.com";
$login_page = "/asa/welcome";
$brand_url = "/asa/home?brand=";
$catalog_page = "/asa/reorder";
$item_page = "/asa/Ajax_cmd/get_item";
$images_path = "/media/vhd/";
$csv_file = "./input/Asics_B2B_" . @date('Ymd_hi') . ".csv";
$csv_header = [
    'Product Code',
    'Style Code',
    'Product Name',
    'Brand',
    'Catalogue',
    'Category',
    'Product Type',
    'Colour',
    'Gender',
    'Cost Excl',
    'WSP Excl',
    'RRP Incl',
    'Discount',
    'Size UK',
    'Size US',
    'Qty available',
    'Image 1',
    'Image 2',
    'Image 3',
    'Image 4',
    'Image 5'
];
$brands = [ 'onitsuka_tiger', 'asics', 'asics_tiger' ];

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

// Login to website
// Save cookies with session id in $cookies_file
function login()
{
    global $cookies_file;
    global $base_url;
    global $login_page;
    $options = array (
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'login=10103&password=S62MRE&browserVers=64.0.3282.186&callUrl=',
        CURLOPT_COOKIEFILE => $cookies_file,
        CURLOPT_COOKIEJAR => $cookies_file
    );
    $url = $base_url . $login_page;
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

// Get cookies from cookies.txt
// Write cookies in $cookies_string
function get_cookies()
{
    global $cookies_file;
    global $cookies_string;
    logg('Get cookies from '.$cookies_file);
    $cookies = file_get_contents($cookies_file);
    //var_dump($cookies);
    $cookies_string = regex( '#(cisession.+)$#', $cookies, 1 );
    $cookies_string = str_replace( "\t", '=', $cookies_string );
}

// Select brand on website
function select_brand($brand)
{
    global $base_url;
    global $brand_url;
    global $cookies_string;
    $options = array (
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Cookie: '.$cookies_string,
            'X-Requested-With: XMLHttpRequest',
            'Accept-Language:en-EN,ru;q=0.9,en-US;q=0.8,en;q=0.7'
        )
    );
    $url = $base_url . $brand_url . $brand;
    logg("Select brand $brand");
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
    return true;
}

// Get catalog page from website
// Return html
function get_catalog()
{
    global $base_url;
    global $catalog_page;
    global $cookies_string;
    $options = array (
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Cookie: '.$cookies_string,
            'X-Requested-With: XMLHttpRequest',
            'Accept-Language:en-EN,ru;q=0.9,en-US;q=0.8,en;q=0.7'
        )
    );
    $url = $base_url . $catalog_page;
    logg("Get catalog from $url");
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

// Parse catalog html
// Return list of items
function parse_catalog($html)
{
    logg('Parse catalog data from html');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    $items = array();
    foreach ( $x->query('//div[@class="ic"]') as $node )
    {
        $id = $node->getAttribute("id");
        $item = array();
        $item['shoeCode'] = regex('#^([\w\d]+)_#', $id, 1);
        $item['shoeCatalog'] = regex('#_([\w\d]+)$#', $id, 1);
        $items[] = $item;
    }
    return $items;
}

// Get item from website
// Return json
function get_item($shoeCode, $shoeCatalog)
{
    global $cookies_string;
    global $base_url;
    global $item_page;
    $fields = 'shoeCode='.$shoeCode.'&shoeCatalog='.$shoeCatalog;
    $options = array (
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'Cookie: '.$cookies_string,
            'X-Requested-With: XMLHttpRequest'
        ),
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields
    );
    $url = $base_url . $item_page;
    logg("Get item from website: $fields");
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

// Parse json with items data
// Write results into csv
function parse_item($json)
{
    global $base_url;
    global $images_path;
    global $csv_handle;
    global $items_count;
    global $scrape_result;
    global $product_codes;
    global $style_codes;
    global $product_codes_with_qty;
    global $style_codes_with_qty;
    global $total_sku_with_qty;
    global $sum_qty;
    global $cur_brand;

    logg('Parse items data from json');
    $arr = json_decode($json, true);
    foreach ( $arr['item_colors'] as $color )
    {
        foreach ( $color['stocks'] as $stock )
        {
            $item = array();
            $item['product_code'] = $arr['code'];
            if ( !in_array( $item['product_code'], $product_codes ) )   // Save unique product codes
                $product_codes[] = $item['product_code'];
            $item['style_code'] = $color['code'];
            if ( !in_array( $item['style_code'], $style_codes ) )   // Save unique style codes
                $style_codes[] = $item['style_code'];
            $item['product_name'] = $arr['name'];
            $item['brand'] = $cur_brand;
            $item['catalogue'] = $color['group'];
            $item['category'] = $arr['category'];
            $item['product_type'] = $arr['productType'];
            $item['colour'] = $color['name'];
            switch ( $arr['ligne'] )
            {
                case 'M': $item['gender'] = 'Men'; break;
                case 'W': $item['gender'] = 'Women'; break;
                case 'U': $item['gender'] = 'Unisex'; break;
                case 'K': $item['gender'] = 'Children'; break;
                default: $item['gender'] = $arr['ligne'];
            }
            if ( $color['discount'] != 0 )
                $item['cost_excl'] = $color['prix_ht'] - $color['prix_ht'] * $color['discount'] / 100;
            else
                $item['cost_excl'] = $color['prix_ht'];
            $item['price'] = $color['prix_ht'];
            $item['rrp'] = $color['pvpc'];
            $item['discount'] = $color['discount'];
            $item['size_uk'] = $stock['ukSize'];
            $item['size_us'] = $stock['usSize'];
            $item['qty_available'] = $stock['quantite'];
            if ( $item['qty_available'] > 0 )
            {
                $total_sku_with_qty++;
                $sum_qty += $item['qty_available'];
                if ( !in_array( $item['product_code'], $product_codes_with_qty ) )   // Save unique product codes with qty
                    $product_codes_with_qty[] = $item['product_code'];
                if ( !in_array( $item['style_code'], $style_codes_with_qty ) )   // Save unique style codes with qty
                    $style_codes_with_qty[] = $item['style_code'];
            }
            if ( $color['image_path'] != '' )
                $item['image'] = $base_url . $images_path . $color['image_path'];
            else
                $item['image'] = '';
            // Additional images
            for ( $i = 1; $i <= 4; $i++ )
                if ( $color['opath'.$i] != '' )
                    $item['image'.$i] = $base_url . $images_path . $color['opath'.$i];
            // Strip commas
            foreach ( $item as $key => $value )
                $item[$key] = str_replace( ',', '', $value );
            //var_dump($item);
            // Put product into csv
            $dump = 'Put item into csv ( ';
            foreach ( $item as $key => $value )
                $dump .= $key . ': ' . $value . ' ';
            $dump .= ' )';
            logg($dump);
            fputcsv($csv_handle, $item);
            $items_count++;
            $scrape_result = true;
        }
    }
}

// Create new log
$log_handle = fopen($scraper_log, "w");
fclose($log_handle);

logg('---------------');
logg('Scraper started');

$start_date = @date('Y-m-d');
$start_time = @date('H:i');
$start_microtime = microtime(true);
$scrape_result = false;

// Create csv and put header
$csv_handle = fopen($csv_file, "w");
fputcsv($csv_handle, $csv_header);
fclose($csv_handle);

login();
get_cookies();

foreach ( $brands as $brand )
{
    select_brand($brand);
    switch ( $brand )
    {
        case 'onitsuka_tiger': $cur_brand = 'Onitsuka Tiger'; break;
        case 'asics': $cur_brand = 'Asics Performance'; break;
        case 'asics_tiger': $cur_brand = 'Asics Tiger'; break;
    }
    $content = get_catalog();
    $items = parse_catalog($content);
    //var_dump($items);
    logg(count($items) . ' items found');
    // Processing items
    logg('Processing items');
    foreach ( $items as $item )
    {
        $content = get_item($item['shoeCode'], $item['shoeCatalog'] );
        //var_dump($content);
        // Parse item
        $csv_handle = fopen($csv_file, "a");
        parse_item($content);
        fclose($csv_handle);
    }
}

// Upload results to Amazon S3
$client = new S3Client([
    'version' => 'latest',
    'region'  => 'eu-west-1',
    'credentials' => [
        'key'    => '',
        'secret' => ''
    ]
]);
logg("Upload output file $csv_file to S3");
$client->putObject(array(
    'Bucket' => 'rws-portal-global',
    'Key'    => 'asics' . str_replace( './', '/', $csv_file ),
    'Body'   => fopen($csv_file, 'r+')
));
logg("Download log file $scraper_csv_log from S3");
$result = $client->getObject(array(
    'Bucket' => 'rws-portal-global',
    'Key'    => 'asics/' . $scraper_csv_log,
    'SaveAs' => $scraper_csv_log
));

logg("Write to csv log");
$csv_log_handle = fopen($scraper_csv_log, "a");
if ($scrape_result) $result_str = 'Success';
else $result_str = 'Failed';
$elapsed_time = ceil( microtime(true) - $start_microtime );
$scrape_duration = gmdate("H:i:s", $elapsed_time);
$product_codes_count = count($product_codes);
$style_codes_count = count($style_codes);
$product_codes_with_qty_count = count($product_codes_with_qty);
$style_codes_with_qty_count = count($style_codes_with_qty);
$log_line = array(
    $start_date,
    $start_time,
    $scrape_duration,
    $result_str,
    $product_codes_count,
    $style_codes_count,
    $items_count,
    $product_codes_with_qty_count,
    $style_codes_with_qty_count,
    $total_sku_with_qty,
    $sum_qty
);
fputcsv($csv_log_handle, $log_line);
fclose($csv_log_handle);

logg("Upload log file $scraper_csv_log to S3");
$client->putObject(array(
    'Bucket' => 'rws-portal-global',
    'Key'    => 'asics/' . $scraper_csv_log,
    'Body'   => fopen($scraper_csv_log, 'r+')
));

logg("-------");
logg("Summary");
logg("Scrape Date: $start_date");
logg("Scrape Time: $start_time");
logg("Scrape Duration: $scrape_duration");
logg("Scrape Result: $result_str");
logg("Total Products Count: $product_codes_count");
logg("Total Styles Count: $style_codes_count");
logg("Total SKUs Count: $items_count");
logg("Total Products with Qty Count: $product_codes_with_qty_count");
logg("Total Styles with Qty Count: $style_codes_with_qty_count");
logg("Total SKUs with Qty Count: $total_sku_with_qty");
logg("Total SKU Qty: $sum_qty");
logg("Elapsed time $elapsed_time s");
logg("Scraper stopped");

?>