<?php
/**
 * @file export.php
 * @brief Get active sites from DB and exporting results
 */
ini_set("memory_limit","2048M");
ini_set('error_reporting', E_ERROR);
set_time_limit(0);
date_default_timezone_set('Africa/Cairo');

require_once 'db.php';

/**
 * @brief Write log into console and file
 * @param string $text  [Log message text]
 */
function write_log($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('export.log', $log, FILE_APPEND | LOCK_EX);
}

/**
 * @brief Run export_site for specifed site ID and current date
 * @param int $site_id  [Site ID for export]
 */
function run_export($site_id)
{
    write_log("Run export for site ID: ".$site_id);
    exec("nohup php export_site.php $site_id ".date('Y-m-d'));
}

/*write_log('Select sites from DB');
$db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);
$query = "SELECT site_id FROM sites WHERE disabled=0 ORDER BY site_id";
$req = $db->query($query);
foreach ($req as $row)
{
    run_export($row['site_id']);
}*/
run_export(8);		//CottonOn
run_export(17);		//OneDayOnly
run_export(22);		//Superbalist

?>