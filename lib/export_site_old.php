<?php
/**
 * @file export_site.php
 * @brief Export results for one site from DB into CSV file and send to cloud storage
 * @details Export results for one site and specified date from DB into CSV file and send to cloud storage. Site ID and export date must be specified in the command line arguments
 */
date_default_timezone_set('Africa/Cairo');
ini_set("memory_limit","2048M");
set_time_limit(0);
error_reporting(E_ALL);

require_once 'db.php';

/**
 * @brief Write log into console and file
 * @param string $text  [Log message text]
 */
function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('export_site.log', $log, FILE_APPEND | LOCK_EX);
}

if (count($argv) < 3) 
{
    logg('No site ID or date specified in the arguments');
    logg('Usage example: export_site.php 5 2018-07-18');
    exit();
}

$site_id = $argv[1];
$date = $argv[2];
logg('Export site ID: '.$site_id.' date: '.$date);

$db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);

// Get site name
logg('Get site name for site ID: '.$site_id);
$query = "SELECT DISTINCT site_name FROM sites WHERE site_id = :site_id";
$req = $db->prepare($query);
$arr = [];
$arr['site_id'] = $site_id;
if (!$req->execute($arr))
{
    logg('DB error');
    logg(json_encode($req->errorInfo()));
    exit();
}
$row = $req->fetch();
if (!isset($row['site_name']))
{
    logg('No site ID found in DB: '.$site_id);
    exit();
}
else $site_name = $row['site_name'];
logg('Site name: '.$site_name);

logg('Select items from product_debug');
$query = "SELECT * FROM product_debug WHERE tracking_date = '$date' AND site_id = '$site_id'";
$req = $db->query($query);
if (!$req)
{
    logg('DB error');
    logg(json_encode($db->errorInfo()));
    exit();
}
$file_name = str_replace(' ', '', strtolower($site_name)).'_'.str_replace('-', '', $date).'.csv';
logg('Write into CSV: '.$file_name);
$file = fopen('../export/'.$file_name, "w");
if (!$file)
{
    logg("Unable to open file ".$file_name);
    exit();
}
$header = ['Tracking Date', 'Site', 'Brand', 'Product Name', 'Product ID', 'Product URL', 'Category', 'Product Type', 'Currency Price', 'Currency', 'Price', 'Discount', 'Product Option', 'Image URL', 'Timestamp'];
fputcsv($file, $header);
$cnt = 0;
foreach ($req as $row)
{
    // Get brand name
    $query = "SELECT DISTINCT brand_name FROM brands WHERE brand_id = '{$row['brand_id']}'";
    $req2 = $db->query($query);
    if (!$req2)
    {
        logg('DB error');
        logg(json_encode($db->errorInfo()));
        exit();
    }
    $row2 = $req2->fetch();
    if (!isset($row2['brand_name']))
    {
        logg('No brand ID found in DB: '.$row2['brand_id']);
        continue;
    }
    $brand_name = $row2['brand_name'];
    // Get currency name
    $query = "SELECT DISTINCT currency_code FROM currencies WHERE currency_id = '{$row['currency_id']}'";
    $req2 = $db->query($query);
    if (!$req2)
    {
        logg('DB error');
        logg(json_encode($db->errorInfo()));
        exit();
    }
    $row2 = $req2->fetch();
    if (!isset($row2['currency_code']))
    {
        logg('No currency ID found in DB: '.$row2['currency_id']);
        continue;
    }
    $currency_code = $row2['currency_code'];
    // Prepare array to put into CSV
    $arr = [];
    $arr[] = $row['tracking_date'];
    $arr[] = $site_name;
    $arr[] = $brand_name;
    $arr[] = $row['product_name'];
    $arr[] = $row['product_id'];
    $arr[] = $row['product_url'];
    $arr[] = $row['category'];
    $arr[] = $row['product_type'];
    $arr[] = $row['currency_price'];
    $arr[] = $currency_code;
    $arr[] = $row['price'];
    $arr[] = $row['discount'];
    $arr[] = $row['product_option'];
    $arr[] = $row['image_url'];
    $arr[] = $row['timestamp'];
    fputcsv($file, $arr);
    $cnt++;
    //break;
}
if ($cnt == 0) {
    logg('No rows found');
    $status = 0;
}
else {
    logg($cnt." rows exported");
    $status = 1;
}

// Update site status in DB
logg('Update site status in DB');
$query = "UPDATE sites SET status='$status' WHERE site_id='$site_id'";
$req = $db->query($query);
if (!$req)
{
    logg('DB error');
    logg(json_encode($db->errorInfo()));
    exit();
}

logg('Finished');

?>