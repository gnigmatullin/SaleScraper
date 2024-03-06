<?php
/**
 * @file upload_drive.php
 * @brief Upload CSV file into Google Drive
 * @details Upload CSV file into Google Drive. CSV file name must be specified in the command line arguments
 */
date_default_timezone_set('Africa/Cairo');
error_reporting(E_ALL);
ini_set("memory_limit","2048M");
set_time_limit(0);

require_once '../vendor/autoload.php';
require_once 'google_functions.php';

define('APPLICATION_NAME', 'Runwaysale App');
define('CREDENTIALS_PATH', 'token.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
define('SCOPES', implode(' ', array(
        Google_Service_Drive::DRIVE,
        Google_Service_Drive::DRIVE_FILE,
        Google_Service_Drive::DRIVE_APPDATA,
        Google_Service_Drive::DRIVE_READONLY,
        Google_Service_Drive::DRIVE_METADATA,
        Google_Service_Drive::DRIVE_METADATA_READONLY
    )
));

/**
 * @brief Write log into console and file
 * @param string $text  [Log message text]
 */
function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('upload_drive.log', $log, FILE_APPEND | LOCK_EX);
}

if (count($argv) < 2)
{
    logg('No CSV file name specified in the arguments');
    exit();
}

$file_name = $argv[1];
logg('Upload CSV: '.$file_name);
$client = getClient();
$service = new Google_Service_Drive($client);
insertFile($service, $file_name, $file_name, '1JsFDg4kVf7Et5VUB5mIYf1not0t6T_pz', '', '../export/'.$file_name);
logg('Finished');

?>