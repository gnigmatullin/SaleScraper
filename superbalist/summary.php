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

$scraper_log = "summary.log";
// Write log
function logg($text)
{
    global $scraper_log;
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents($scraper_log, $log , FILE_APPEND | LOCK_EX);
}

// Write item details into google sheet
function write_item_details($item)
{
    // Summary file id
    $spreadsheetId = "1qhcYgN3BnnpkXQ2sx5sOK5UKJPmRjKNgZ23AoVwEOgc";
    logg('Write item details into google sheet '.$spreadsheetId);
    $dump = '';
    foreach ( $item as $key => $value )
        $dump .= $key . ': ' . $value . ' ';
    logg($dump);
    // Get the API client and construct the service object
    $client = getClient();
    $service = new Google_Service_Sheets($client);
    logg('Add new row with item details into google sheet');
    $dump = '';
    foreach ( $item as $key => $value )
        $dump .= $key . ': ' . $value . ' ';
    logg($dump);
    $range  = 'Superbalist!A1:O1';
    $body   = new Google_Service_Sheets_ValueRange([
                                                    'values' => [
                                                        [
                                                            $item['date'],
                                                            $item['brand'],
                                                            $item['avg_discount'],
                                                            $item['discount1'],
                                                            $item['discount2'],
                                                            $item['discount3'],
                                                            $item['discount4'],
                                                            $item['discount5'],
                                                            $item['discount6'],
                                                            $item['qty1'],
                                                            $item['qty2'],
                                                            $item['qty3'],
                                                            $item['qty4'],
                                                            $item['qty5'],
                                                            $item['qty6']
                                                        ]
                                                    ]
                                                ]
    );
    try {
        $result = $service->spreadsheets_values->append($spreadsheetId, $range, $body, ['valueInputOption' => 'RAW']);
        $upd    = $result->getUpdates();
        if ($upd != null)
            logg($upd->updatedColumns.' cells written');
        else
            logg('No cells written');
        sleep(2);
    }
    catch(Google_Exception $exception)
    {
        logg('Google sheets API error: '.$exception->getMessage());
        sleep(2);
        return false;
    }
    return true;
}

logg('---------------');
logg('Scraper started');
$client = getClient();

// Get today file id
$service_drive       = new Google_Service_Drive($client);
$file_name = 'Superbalist '.date('Y-m-d', time() - 3600 * 24);
logg('Get id for file: '.$file_name);
$spreadsheetId = searchFile($service_drive, $file_name);
logg('File id: '.$spreadsheetId);
// Process all rows
$service_sheets = new Google_Service_Sheets($client);
$range = 'Sheet1!A2:K';
$response = $service_sheets->spreadsheets_values->get($spreadsheetId, $range);
$values = $response->getValues();
$items = [];
$i = 0;
foreach ( $values as $key => $value )
{
    //var_dump($value);
    if ( array_key_exists($value[3], $items) ) // product of existing brand
    {
        $items[$value[3]]['number_of_products']++;
        $discount = abs($value[5]);
        $items[$value[3]]['sum_of_discounts'] += $discount;
        if ($discount < 10)
        {
            $items[$value[3]]['discount1']++;
            $items[$value[3]]['qty1'] += $value[8];
        }
        else if ($discount < 20)
        {
            $items[$value[3]]['discount2']++;
            $items[$value[3]]['qty2'] += $value[8];
        }
        else if ($discount < 30)
        {
            $items[$value[3]]['discount3']++;
            $items[$value[3]]['qty3'] += $value[8];
        }
        else if ($discount < 40)
        {
            $items[$value[3]]['discount4']++;
            $items[$value[3]]['qty4'] += $value[8];
        }
        else if ($discount < 50)
        {
            $items[$value[3]]['discount5']++;
            $items[$value[3]]['qty5'] += $value[8];
        }
        else
        {
            $items[$value[3]]['discount6']++;
            $items[$value[3]]['qty6'] += $value[8];
        }
    }
    else // product of new brand
    {
        $item = [];
        $item['date'] = $value[0];
        $item['brand'] = $value[3];
        $item['number_of_products'] = 1;
        $discount = abs($value[5]);
        $item['sum_of_discounts'] = $discount;
        $item['avg_discount'] = $discount;
        $item['discount1'] = 0; $item['qty1'] = 0;
        $item['discount2'] = 0; $item['qty2'] = 0;
        $item['discount3'] = 0; $item['qty3'] = 0;
        $item['discount4'] = 0; $item['qty4'] = 0;
        $item['discount5'] = 0; $item['qty5'] = 0;
        $item['discount6'] = 0; $item['qty6'] = 0;
        if ($discount < 10)
        {
            $item['discount1']++;
            $item['qty1'] += $value[8];
        }
        else if ($discount < 20)
        {
            $item['discount2']++;
            $item['qty2'] += $value[8];
        }
        else if ($discount < 30)
        {
            $item['discount3']++;
            $item['qty3'] += $value[8];
        }
        else if ($discount < 40)
        {
            $item['discount4']++;
            $item['qty4'] += $value[8];
        }
        else if ($discount < 50)
        {
            $item['discount5']++;
            $item['qty5'] += $value[8];
        }
        else
        {
            $item['discount6']++;
            $item['qty6'] += $value[8];
        }
        $items[$value[3]] = $item;
    }
}
//var_dump($items);
foreach ( $items as $item )
{
    $item['avg_discount'] = ceil($item['sum_of_discounts'] / $item['number_of_products']);
    $res = false;
    $cnt = 1;
    while (!$res)
    {
        if ($cnt != 1) logg('Write item details attempt '.$cnt);
        $res = write_item_details($item);    // write item
        if ($cnt >= 5) break;
        $cnt++;
    }
    if ($cnt == 5) logg('Error. Item details is not written after 5 attempts');     
}
logg("Scraper stopped");

?>