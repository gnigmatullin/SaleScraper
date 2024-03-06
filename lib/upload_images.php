<?php
/**
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
    file_put_contents('upload_images.log', $log, FILE_APPEND | LOCK_EX);
}

function filter_text($text)
{
    $text = preg_replace('#[^\w^\d^\.^:]#', ' ', $text);
    $text = preg_replace('#\s+#', ' ', $text);
    $text = preg_replace('#[\r\n]#', ' ', $text);
    $text = trim($text);
    return $text;
}

function curl($url)
{
    $curl_options = [
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/68.0.3440.106 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXREDIRS => 5
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt_array($ch, $curl_options);
    $html = curl_exec($ch);
    if(!curl_errno($ch))
    {
        $info = curl_getinfo($ch);
        logg("Response code ".$info['http_code']);
        if (!in_array($info['http_code'], [200, 201, 202, 203, 204, 205, 206, 207, 208, 226, 300, 301, 302, 303, 304, 305, 306, 307, 308])) {
            return -1;                  // Not allowed http code
        }
    }
    else
    {
        $err = curl_error( $ch );
        logg("CURL error ".curl_errno($ch)." ".$err, 2);
        return -1;                  // CURL error
    }
    curl_close($ch);
    return $html;
}

function regex($regex, $input, $output = 0)
{
    $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
    if (!$match)
        $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
    return $match;
}
    
function load_image($image_url)
{
    $image_url = trim($image_url);
    if ($image_url == '') return;
    logg("Load image URL: $image_url");
    $image = curl($image_url);
    $file_name = regex('#/([^/]+\.jpg)#', $image_url, 1);
    logg("Save to file: ".$file_name);
    file_put_contents('../images/'.$file_name, $image);
}

$site_id = $argv[1];
if ($argv[3] == 'debug') $debug = true;
else $debug = false;

logg('Export site ID: '.$site_id.' date: '.$date);
if ($debug) logg('Export from debug');

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
    $query = "SELECT * FROM product_tracking WHERE site_id = '$site_id'";
}
else {
	logg('Select items from product_debug');
    $query = "SELECT * FROM product_debug WHERE site_id = '$site_id'";
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
    load_image($row['product_image1']);
    load_image($row['product_image2']);
    load_image($row['product_image3']);
    load_image($row['product_image4']);
}

logg('Finished');

?>