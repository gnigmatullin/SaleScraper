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

$scraper_log = "summary.log";

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

// Write item (5 attempts)
function write_item($item)
{
    $res = -2;
    $i = 0;
    while ($res == -2)
    {
        $res = write_item_details($item);
        $i++;
        if ($i > 4) break;
        sleep(1);
    }
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
    $range  = 'One Day Only!A1:M1';
    var_dump($item);
    $body   = new Google_Service_Sheets_ValueRange([
                                                    'values' => [
                                                        [
                                                            $item['date'],
                                                            $item['brand'],
                                                            $item['product'],
                                                            $item['number_of_products'],
                                                            $item['total_units'],
                                                            $item['ave_units'],
                                                            $item['discount'],
                                                            $item['price'],
                                                            $item['min_units_sold'],
                                                            $item['total_selling_value'],
                                                            $item['units_added'],
                                                            $item['url'],
                                                            $item['id']
                                                        ]
                                                    ]
                                                ]
    );
    try
    {
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
        return -2;
    }
    sleep(1);
}

logg('---------------');
logg('Scraper started');
$client = getClient();
// Get today file id
$service_drive       = new Google_Service_Drive($client);
$spreadsheetId = searchFile($service_drive, 'One Day Only '.date('Y-m-d'));
logg('Today file id: '.$spreadsheetId);
// Process all rows
$service_sheets = new Google_Service_Sheets($client);
$range = 'Sheet1!A2:AH';
$response = $service_sheets->spreadsheets_values->get($spreadsheetId, $range);
$values = $response->getValues();
$item = [];
$i = 0;
foreach ( $values as $key => $value )
{
    //var_dump($value);
    if ( $value[33] == $item['id'] ) // option of the same product
    {
        $item['number_of_products']++;
        $item['sum_of_prices'] += $value[4];
        $item['min_units_sold'] += $value[6];
        $item['units_added'] += $value[7];
        $item['total_units'] += $value[8];
    }
    else // new product
    {
        if (isset($item['id']))
        {
            $item['price'] = ceil($item['sum_of_prices'] / $item['number_of_products']);
            $item['total_selling_value'] = $item['min_units_sold'] * $item['price'];
            $item['ave_units'] = ceil($item['total_units'] / $item['number_of_products']);
            write_item($item);    // write item
        }
        $item['date'] = $value[0];
        $item['brand'] = $value[1];
        $item['product'] = $value[2];
        $item['discount'] = $value[3];
        $item['sum_of_prices'] = $value[4];
        $item['number_of_products'] = 1;
        $item['min_units_sold'] = $value[6];
        $item['units_added'] = $value[7];
        $item['total_units'] = $value[8];
        $item['url'] = $value[32];
        $item['id'] = $value[33];
    }
}

logg("Scraper stopped");

?>