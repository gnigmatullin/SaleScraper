<?php
/**
 * @file products_count.php
 * @brief Get results count from DB
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
    file_put_contents('products_count.log', $log, FILE_APPEND | LOCK_EX);
}

function filter_text($text)
{
    $text = preg_replace('#[^\w^\d^\.^:]#', ' ', $text);
    $text = preg_replace('#\s+#', ' ', $text);
    $text = preg_replace('#[\r\n]#', ' ', $text);
    $text = trim($text);
    return $text;
}

if (count($argv) < 2) 
{
    logg('No site ID or date specified in the arguments');
    logg('Usage example: products_count.php ID');
    exit();
}

$site_id = $argv[1];
$date = date('Y-m-d');
if ($argv[2] == 'debug') $debug = true;
else $debug = false;
logg('Site ID: '.$site_id.' date: '.$date);
if ($debug) logg('From debug DB');

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

if (!$debug) {
	logg('Select items from product_tracking');
	$query = "SELECT count(*) FROM product_tracking WHERE tracking_date = '$date' AND site_id = '$site_id'";
    $query2 = "SELECT COUNT(DISTINCT product_url) FROM product_tracking WHERE tracking_date = '$date' AND site_id = '$site_id'";
}
else {
	logg('Select items from product_debug');
	$query = "SELECT count(*) FROM product_debug WHERE tracking_date = '$date' AND site_id = '$site_id'";
    $query2 = "SELECT COUNT(DISTINCT product_url) FROM product_debug WHERE tracking_date = '$date' AND site_id = '$site_id'";
}
$req = $db->query($query);
if (!$req)
{
    logg('DB error');
    logg(json_encode($db->errorInfo()));
    exit();
}

foreach ($req as $row)
{
    var_dump($row);
}

$req = $db->query($query2);
if (!$req)
{
    logg('DB error');
    logg(json_encode($db->errorInfo()));
    exit();
}

foreach ($req as $row)
{
    var_dump($row);
}

?>