<?php
/**
 * @file clear_db.php
 * @brief Clear DB from old results
 */
ini_set("memory_limit","512M");
set_time_limit(0);
date_default_timezone_set('Africa/Cairo');
require_once 'db.php';

/**
 * Write log into console and file
 * @param string $text  [Log message text]
 */
function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('export.log', $log, FILE_APPEND | LOCK_EX);
}

logg('Clearing DB');
$db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);
$query = "DELETE FROM product_tracking WHERE (timestamp < NOW() - INTERVAL 1 DAY)";
$req = $db->query($query);
if (!$req)
{
    logg('DB error');
    logg(json_encode($db->errorInfo()));
    exit();
}

logg('Clearing export folder');
$files = glob("../export/*.csv");
$now   = time();
foreach ($files as $file) {
    if (is_file($file)) {
        if ($now - filemtime($file) >= 60 * 60 * 24 * 1) {
          unlink($file);
        }
    }
}

logg('Finished');

?>