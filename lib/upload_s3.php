<?php
/**
 * @file upload_s3.php
 * @brief Upload CSV file into Amazon S3 Bucket
 * @details Upload CSV file into Amazon S3 Bucket. CSV file name must be specified in the command line arguments
 */
date_default_timezone_set('Africa/Cairo');
error_reporting(E_ALL);
ini_set("memory_limit","2048M");
set_time_limit(0);

require_once '../vendor/autoload.php';
use Aws\S3\S3Client;

/**
 * @brief Write log into console and file
 * @param string $text  [Log message text]
 */
function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('upload_s3.log', $log, FILE_APPEND | LOCK_EX);
}

/**
 * Run regexp on a string and return the result
 * @param STRING $regex     [Regex string]
 * @param STRING $input     [Input string for regexp]
 * @param INT $output       [0 - return matches array, 1 - return matched value]
 */
function regex($regex, $input, $output = 0)
{
    $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
    if (!$match)
        $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
    return $match;
}

if (count($argv) < 2)
{
    logg('No CSV file name specified in the arguments');
    exit();
}

$file_name = $argv[1];
// Get site name from file name
$site_name = regex('#^([^_]+)#', $file_name, 1);
logg('Upload CSV: '.$file_name);
$client = new S3Client([
    'version' => 'latest',
    'region'  => 'eu-west-1',
    'credentials' => [
        'key'    => '',
        'secret' => ''
    ]
]);
logg("Upload CSV {$file_name} into S3");
$file_date=regex('#(\d+)#', $file_name, 1);
$client->putObject(array(
    'Bucket' => 'rws-bi-competitor-tracking-scrapes',
    'Key'    => $site_name.'/'.date('Y/m/d', strtotime($file_date)).'/'.$file_name,
    'Body'   => fopen('../export/'.$file_name, 'r+')
));
logg('Finished');

?>