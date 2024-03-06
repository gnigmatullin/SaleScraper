<?php
require_once '../vendor/autoload.php';
require_once 'google_functions.php';

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ERROR);

define('APPLICATION_NAME', 'One Day Only App');
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

$scraper_log = "deals_tracking.log";
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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
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
// Return links to items details
function parse_catalog($url)
{
    $html = get_page($url);
    logg('Parse detail links from catalogue html');
    $links = array();
    $json = regex('#<script\sid=\"__NEXT_DATA__\"\stype=\"application/json\">([\s\S]+?)</script>#', $html, 1);
    $arr = json_decode($json, true);
    foreach ($arr['props']['pageProps']['homePage']['items'] as $item_node) {
        foreach ($item_node['props']['items'] as $item) {
            if ($item['id'] != '') {
                $links[] = '/products/'.$item['id'];
            }
        }
    }
    //var_dump($links);
    logg(count($links).' links parsed');
    return $links;
}

// Get quantity
function get_qty($html, $option_value)
{
    logg('Get quantity for option value '.$option_value);
    $json = regex( '#var\sco_qtys\s=\s(\{.+\})#', $html, 1 );
    if ($json)
    {
        $qtys = json_decode($json, true);
        foreach ( $qtys as $value => $qty )
        {
            if ( $value == $option_value ) return $qty;
        }
    }
}

function find_dependents(&$options, &$option, &$pids)
{
    $pids[] = $option['id'];
    if (count($option['dependents']) == 0) 
    {
        $index = count($pids) - 1;
        $flag = true;
        while ($index >= 0)
        {
            $pid = $pids[$index];
            if ($flag)
            {
                foreach ($options[$pid]['dependents'] as $id => $dep)
                {
                    unset($options[$pid]['dependents'][$id]);
                    break;
                }
            }
            if (count($options[$pid]['dependents']) == 0)
            {
                $flag = true;
            }
            else
                $flag = false;
            $index--;
        }
        return;
    }
    $dep = reset($options[$option['id']]['dependents']);
    foreach ($options as $id => $opt)
    {
        if ($id == $dep)
        {
            find_dependents($options, $opt, $pids);
        }
    }
    return;
}

function get_node_value($xpath, $query, $element = false)
{
    $value = false;
    if (!$element) {    // Search in all document
        foreach ($xpath->query($query) as $node) {
            $value = trim($node->nodeValue);
            break;
        }
    }
    else {    // Search in specified element only
        foreach ($xpath->query($query, $element) as $node) {
            $value = trim($node->nodeValue);
            break;
        }
    }
    return $value;
}

// Parse item details
function parse_item($spreadsheetId, $day_run_number, $url)
{
    $url = 'https://www.onedayonly.co.za'.$url;
    $html = -1;
    $i = 0;
    while ($html == -1)
    {
        $html = get_page($url);
        $i++;
        if ($i > 4) break;
    }
    if ($html == -1)
    {
        logg('Can\'t load page');
        return false;
    }
    logg('Parse item details from html');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    // Check if deal expired
    $nodes = $x->query('//h5[ contains( ., "Deal expired" ) ]');
    if ( $nodes->length != 0 )
    {
        logg('Deal is expired');
        return false;
    }

    $item = [];
    $item['tracking_date'] = date('Y-m-d');
    $item['product_url'] = $url;

    // Brand
    $item['brand'] = get_node_value($x, '//h2[ @class = "css-y306bx" ]');
    if (!$item['brand']) {
        logg('No brand name found', 1);
        logg('HTML: '.$html);
        return false;
    }

    // Discount
    $discount = get_node_value($x, '//span[ @class = "css-12gujv2" ]');
    if (!$discount)
        $item['discount'] = 0;
    else $item['discount'] = regex('#(\d+)#', $discount, 1);

    // Product name
    $item['product_name'] = get_node_value($x, '//h2[ @class = "css-1cox266" ]');
    if (!$item['product_name']) {
        logg('No product name found', 1);
        logg('HTML: '.$html);
        return false;
    }

    // Category
    $item['category'] = '';
    $item['product_type'] = '';

    // Price
    $price = get_node_value($x,'//h2[ @id = "product-price" ]');
    if ($price) {
        $price = regex('#([\d,]+)#', $price, 1);
        $item['price'] = str_replace(',', '', $price);
    } else {
        logg('No price found', 1);
        $item['price'] = 0;
    }

    // Product ID
    $product_id = str_replace('https://www.onedayonly.co.za/products/', '', $url);
    if (!$product_id) {
        logg('No product ID found', 1);
        logg('HTML: '.$html);
        return false;
    }

    // Get all product variations
    $json = regex('#<script id="__NEXT_DATA__" type="application/json">(\{[\s\S]+?\})</script>#', $html, 1);
    $arr = json_decode($json, true);
    $sp_found = false;
    if (isset($arr['props']['pageProps']['product']))
    {
        if (count($arr['props']['pageProps']['product']['customizableOptions']) > 0) {
            foreach ($arr['props']['pageProps']['product']['customizableOptions'][0]['values'] as $prop_name => $prop) {
                if (!isset($prop['id'])) {
                    continue;
                }
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
                write_item($spreadsheetId, $day_run_number, $item);
            }
        }
        else {  // no options
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
            write_item($spreadsheetId, $day_run_number, $item);
        }
    }
}

// Write item
function write_item($spreadsheetId, $day_run_number, $item)
{
    $res = -2;
    $i = 0;
    while ($res == -2)
    {
        $res = write_item_details($spreadsheetId, $day_run_number, $item);
        $i++;
        if ($i > 4) break;
        sleep(1);
    }
}

// Write item details into google sheet
function write_item_details($spreadsheetId, $day_run_number, $item)
{
    logg('Write item details into google sheet '.$spreadsheetId);
    $dump = '';
    foreach ( $item as $key => $value )
        $dump .= $key . ': ' . $value . ' ';
    logg($dump);
    // Get run time
    // Get the API client and construct the service object
    $client = getClient();
    $service = new Google_Service_Sheets($client);
    if ( $day_run_number == 1 )
    {
        logg('Add new row with item details into google sheet');
        $range  = 'Sheet1!A1:AH1';
        $body   = new Google_Service_Sheets_ValueRange([
                                                        'values' => [
                                                            [
                                                                $item['tracking_date'],
                                                                $item['brand'],
                                                                $item['product_name'],
                                                                $item['discount'],
                                                                $item['price'],
                                                                $item['product_option'],
                                                                '0',
                                                                '0',
                                                                $item['qty_available'],
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                '',
                                                                $item['product_url'],
                                                                $item['product_id']
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
    else
    {
        logg('Search in google sheet for item date: ' . $item['tracking_date'] . ' and ID: ' . $item['product_id'] . ' and options ' . $item['product_option']);
        $range = 'Sheet1!A2:AH';
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();
        // Get current column for quantity
        switch ($day_run_number)
        {
            case 1: $col = 'I'; break;
            case 2: $col = 'J'; break;
            case 3: $col = 'K'; break;
            case 4: $col = 'L'; break;
            case 5: $col = 'M'; break;
            case 6: $col = 'N'; break;
            case 7: $col = 'O'; break;
            case 8: $col = 'P'; break;
            case 9: $col = 'Q'; break;
            case 10: $col = 'R'; break;
            case 11: $col = 'S'; break;
            case 12: $col = 'T'; break;
            case 13: $col = 'U'; break;
            case 14: $col = 'V'; break;
            case 15: $col = 'W'; break;
            case 16: $col = 'X'; break;
            case 17: $col = 'Y'; break;
            case 18: $col = 'Z'; break;
            case 19: $col = 'AA'; break;
            case 20: $col = 'AB'; break;
            case 21: $col = 'AC'; break;
            case 22: $col = 'AD'; break;
            case 23: $col = 'AE'; break;
            case 24: $col = 'AF'; break;
            default: logg('Wrong day run number: '.$day_run_number);
                return -1;
                break;
        }
        $row_number = 0;
        foreach ( $values as $key => $value )
        {
            //var_dump($value);
            if ( ( $value[0] == $item['tracking_date'] ) && ( $value[5] == $item['product_option'] ) && ( $value[33] == $item['product_id'] ) )
            {
                $row_number = $key + 2;
                $units_sold = $value[6];
                $units_added = $value[7];
                $prev_qty = $value[$day_run_number+6];
                break;
            }
        }
        if ( $row_number != 0 )     // Items row found
        {
            logg('Item found at row '.$row_number);
            logg('Previous quantity: '.$prev_qty);
            logg('Current quantity: '.$item['qty_available']);
            if ($prev_qty > $item['qty_available'])
            {
                $diff = $prev_qty - $item['qty_available'];
                logg($diff.' units sold');
                $units_sold += $diff;
            }
            else if ($prev_qty < $item['qty_available'])
            {
                $diff = $item['qty_available'] - $prev_qty;
                logg($diff.' units added');
                $units_added += $diff;
            }
            logg('Write details at row '.$row_number);
            logg('Write at column '.$col);
            try {
                $data = [];
                // Total units (quantity)
                $data[] = new Google_Service_Sheets_ValueRange([
                                                                'range' => 'Sheet1!'.$col.$row_number,
                                                                'values' => [ [$item['qty_available']] ]
                                                            ]);
                // Units sold
                $data[] = new Google_Service_Sheets_ValueRange([
                                                                'range' => 'Sheet1!G'.$row_number,
                                                                'values' => [ [$units_sold] ]
                                                            ]);
                // Units added
                $data[] = new Google_Service_Sheets_ValueRange([
                                                                'range' => 'Sheet1!H'.$row_number,
                                                                'values' => [ [$units_added] ]
                                                            ]);
                // Write into row
                $body = new Google_Service_Sheets_BatchUpdateValuesRequest([
                                                                            'valueInputOption' => 'RAW',
                                                                            'data' => $data
                                                                        ]);
                try{
                    $result = $service->spreadsheets_values->batchUpdate($spreadsheetId, $body);
                    $upd = $result->getTotalUpdatedCells();
                    if ( $upd != null )
                        logg($upd . ' cells written');
                    else
                        logg('No cells written');
                }
                catch(Google_Exception $exception)
                {
                    logg('Google sheets API error: '.$exception->getMessage());
                }
                sleep(1);
            }
            catch(Google_Exception $exception)
            {
                logg('Google sheets API error: '.$exception->getMessage());
                return -2;
            }
        }
        else logg('Item not found in google sheet');
    }
    return 1;
}

logg('---------------');
logg('Scraper started');
$run_time = date('H:i');
//debug
//$run_time = '00:10';
logg('Run time: '.$run_time);
$day_run_number = intval(substr($run_time, 0, 2)) + 1;
logg($day_run_number . ' run at the day');
$client = getClient();
if ( $day_run_number == 1 ) {
    // Create a new file based on template
    $service       = new Google_Service_Drive($client);
    $copy          = copyFile($service, '1D7YfwlvTe1wywPXxenCsPUJYwAaxF6IunfrehpKDt-o', 'One Day Only '.date('Y-m-d'));
    $spreadsheetId = $copy->id;
}
else {
    // Get today file id
    $service       = new Google_Service_Drive($client);
    $spreadsheetId = searchFile($service, 'One Day Only '.date('Y-m-d'));
}
$links = parse_catalog('https://www.onedayonly.co.za');
//var_dump($links);
$cnt = 0;
foreach ( $links as $link )
{
    parse_item($spreadsheetId, $day_run_number, $link);
    $cnt++;
}
logg("Scraper stopped");
