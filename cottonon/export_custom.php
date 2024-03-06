<?php
ini_set("memory_limit","2048M");
set_time_limit(0);
error_reporting(E_ALL);

require_once '../lib/db.php';

function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('export_custom.log', $log, FILE_APPEND | LOCK_EX);
}

$db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);

logg('Select items from product_tracking');
$query = "SELECT * FROM product_debug";
$req = $db->query($query);
if (!$req)
{
    logg('DB error');
    logg(json_encode($db->errorInfo()));
    exit();
}
$file_name = 'cottonon.csv';
logg('Write into CSV: '.$file_name);
$file = fopen($file_name, "w");
if (!$file)
{
    logg("Unable to open file ".$file_name);
    exit();
}
$header = ['Product ID', 'Product Description'];
fputcsv($file, $header);
$cnt = 0;
foreach ($req as $row)
{
    // Prepare array to put into CSV
    $arr = [];
    $arr[] = $row['product_id'];
    $arr[] = $row['product_description'];
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