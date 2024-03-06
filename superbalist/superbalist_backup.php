<?php
require_once '../vendor/autoload.php';
require_once '../lib/google_functions.php';

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

define('APPLICATION_NAME', 'Superbalist App');
define('CREDENTIALS_PATH', 'token.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
define('SCOPES', implode(' ', array(
        Google_Service_Sheets::SPREADSHEETS,
        Google_Service_Drive::DRIVE,
        Google_Service_Drive::DRIVE_FILE,
        Google_Service_Drive::DRIVE_APPDATA,
        Google_Service_Drive::DRIVE_READONLY,
        Google_Service_Drive::DRIVE_METADATA,
        Google_Service_Drive::DRIVE_METADATA_READONLY
    )
));

$scraper_log = "superbalist.log";
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
    if (!$match)
        $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
    return $match;
}

// Get page from website
// Return html
function get_page($url)
{
    $options = array (
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.79 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 30,
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
        if ( $info['http_code'] != 200 ) return -1;
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

function load_page($url)
{
    $i = 0;
    $html = -1;
    while ($html == -1)
    {
        $html = get_page($url);
        $i++;
        if ($i > 4) break;
    }
    if ($html == -1) logg('Can\'t load page');
    return $html;
}

// Parse catalog html
function parse_catalog($url)
{
    $html = load_page($url);
    if ($html == -1) return;
    logg('Parse catalogue');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    foreach ( $x->query('//h4[ @class = "header-results" ]') as $node )
    {
        $page_count = $node->nodeValue;
    }
    if (!preg_match('#[\d,]+#', $page_count))
    {
        logg('No pages count found');
        logg('HTML: '.$html);
    }
    $page_count = regex('#([\d,]+)#', $page_count, 1);
    $page_count = str_replace(',', '', $page_count);
    $page_count = ceil($page_count / 72);
    logg($page_count . ' pages found');
    logg('Parse catalogue page 1');
    parse_catalog_page($html);
    for ( $page = 2; $page <= $page_count; $page++ )
    {
        $page_html = load_page('https://superbalist.com/browse?grid=8&page=' . $page);
        if ($page_html == -1) continue;
        logg('Parse catalogue page ' . $page);
        parse_catalog_page($page_html);
        //break;
    }
}

// Parse catalog page
function parse_catalog_page($html)
{
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    $links = [];
    foreach ( $x->query('//div[ contains( @class, "bucket-product-with-details" ) ]/a') as $node )
    {
        $link = $node->getAttribute('href');
        if (strpos($link, 'https://superbalist.com') === false)
            $link = 'https://superbalist.com' . $link;
        $links[] = $link;
    }
    //var_dump($links);
    if (count($links) == 0)
    {
        logg('No items found');
        logg('HTML: '.$html);
        die();
    }
    logg(count($links) . ' item links found');
    foreach ($links as $link)
    {
        parse_item($link);
        //break;
    }
}

// Parse item details
function parse_item($url)
{
    $html = load_page($url);
    if ($html == -1) return;
    logg('Parse item details from html');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);

    $item = array();
    $item['date'] = date('d.m.Y');
    $item['url'] = $url;
    $nodes = $x->query('//meta[ @property = "product:category" ]');
    foreach ( $nodes as $node )
    {
        $category = $node->getAttribute('content');
        break;
    }
    if ( !isset($category) )
    {
        $item['section'] = '';
        $item['sub_section'] = '';
        logg('No category found');
    }
    $item['section'] = regex('#^([\s\S]+)\s-#', $category, 1);
    $item['sub_section'] = regex('#-\s([\s\S]+)#', $category, 1);
    $nodes = $x->query('//a[ contains(@class, "pdp-brand") ]/span');
    foreach ( $nodes as $node )
    {
        $item['brand'] = $node->nodeValue;
        break;
    }
    if ( !isset($item['brand']) )
    {
        logg('Brand not found. Item skipped');
        logg($html);
        return false;
    }
    $nodes = $x->query('//span[ @class = "percentage-reduced percentage-reduced-sale" ]');
    foreach ( $nodes as $node )
    {
        $item['discount'] = $node->nodeValue;
        break;
    }
    if ( !isset($item['discount']) )
    {
        logg('Discount not found');
        $item['discount'] = '';
    }
    $nodes = $x->query('//h1[ @class = "headline-tight" ]');
    foreach ( $nodes as $node )
    {
        $item['product_name'] = trim($node->nodeValue);
        break;
    }
    if ( !isset($item['product_name']) )
    {
        logg('Product name not found. Item skipped');
        logg('HTML: '.$html);
        return false;
    }
    /*$nodes = $x->query('//span[ @class = "product_id" ]');
    foreach ( $nodes as $node )
    {
        $item['id'] = $node->nodeValue;
        break;
    }*/
    $item['id'] = regex('#(\d+)$#', $url, 1);
    if ( !isset($item['id']) )
    {
        logg('ID not found. Item skipped');
        logg('HTML: '.$html);
        return false;
    }
    // Get all product variations
    $json = regex('#superbalist\.appdata\s=\s(\{[\s\S]+?\});#', $html, 1);
    //logg($json);
    $arr = json_decode($json, true);
    if (isset($arr['page_impression']['metadata']['variations']))
    {
        foreach ($arr['page_impression']['metadata']['variations'] as $variation)
        {
            if ( isset($variation['fields']['Size']) )
                $item['options'] = $variation['fields']['Size'];
            else if ( isset($variation['fields']['Type']) )
                $item['options'] = $variation['fields']['Type'];
            else
            {
                logg('No field size or type found');
                //logg(json_encode($variation));
                $item['options'] = '';
            }
            $item['price'] = $variation['price'];
            $item['qty'] = $variation['quantity'];
            write_item_details($item);
        }
        return true;
    }
    else
    {
        logg('No variations found in appdata. Search initial state');
        $json = regex('#window\.__INITIAL_STATE__=(\{[\s\S]+?\});#', $html, 1);
        //logg($json);
        $arr = json_decode($json, true);
        //var_dump($arr['product']);
        if (isset($arr['product']))
        {
            foreach ($arr['product'] as $product)
            {
                if (isset($product['variations']))
                {
                    foreach ($product['variations'] as $variation)
                    {
                        if ( isset($variation['fields']['Size']) )
                        $item['options'] = $variation['fields']['Size'];
                        else if ( isset($variation['fields']['Type']) )
                            $item['options'] = $variation['fields']['Type'];
                        else
                        {
                            logg('No field size or type found');
                            //logg(json_encode($variation));
                            $item['options'] = '';
                        }
                        $item['price'] = $variation['price'];
                        $item['qty'] = $variation['quantity'];
                        write_item_details($item);
                    }
                }
                else
                {
                    logg('No variations found');
                    logg('JSON: '.json_encode($product));
                    logg('HTML: '.$html);
                }
            }
        }
        else
        {
            logg('No variations found');
            logg('JSON: '.json_encode($json));
            logg('HTML: '.$html);
        }
    }
}

// Write item details into google sheet
function write_item_details($item)
{
    global $spreadsheetId;
    logg('Write item details into google sheet '.$spreadsheetId);
    $dump = '';
    foreach ( $item as $key => $value )
        $dump .= $key . ': ' . $value . ' ';
    logg($dump);
    // Get the API client and construct the service object
    $client = getClient();
    $service = new Google_Service_Sheets($client);
    logg('Add new row with item details into google sheet');
    $range  = 'Sheet1!A1:K1';
    $body   = new Google_Service_Sheets_ValueRange([
                                                    'values' => [
                                                        [
                                                            $item['date'],
                                                            $item['section'],
                                                            $item['sub_section'],
                                                            $item['brand'],
                                                            $item['product_name'],
                                                            $item['discount'],
                                                            $item['price'],
                                                            $item['options'],
                                                            $item['qty'],
                                                            $item['url'],
                                                            $item['id']
                                                        ]
                                                    ]
                                                ]
    );
    try{
        $result = $service->spreadsheets_values->append($spreadsheetId, $range, $body, ['valueInputOption' => 'RAW']);
        $upd    = $result->getUpdates();
        if ($upd != null)
            logg($upd->updatedColumns.' cells written');
        else
            logg('No cells written');
    }
    catch(Google_Exception $exception)
    {
        logg('Google sheets API error: '.$exception->getMessage());
    }
    sleep(1);
}

file_put_contents($scraper_log, "");
logg('---------------');
logg('Scraper started');
$client = getClient();
// Create a new file based on template
$service       = new Google_Service_Drive($client);
$copy          = copyFile($service, '1pYVYoGvCRy11d-9n5KZhY-eWKiT1r343ywQJsOK5qcQ', 'Superbalist '.date('Y-m-d'));
$spreadsheetId = $copy->id;
parse_catalog('https://superbalist.com/browse?grid=8');
//parse_item('https://superbalist.com/apartment/art-decor/art/the-pizza/174272?rk=8');
logg("Scraper stopped");
