<?php

require 'vendor/autoload.php';
use Aws\S3\S3Client;

// Instantiate an Amazon S3 client.
$client = new S3Client([
    'version' => 'latest',
    'region'  => 'eu-west-1',
    'credentials' => [
        'key'    => '',
        'secret' => ''
    ]
]);

print("\n\nList Buckets\n\n");

$result = $client->listBuckets();

foreach ($result['Buckets'] as $bucket) {
    // Each Bucket value will contain a Name and CreationDate
    echo "{$bucket['Name']} - {$bucket['CreationDate']}\n";
}

/*
print("\n\nList objects\n\n");

$iterator = $client->getIterator('ListObjects', array(
    'Bucket' => 'rws-portal-global'
));

foreach ($iterator as $object) {
    echo $object['Key'] . "\n";
}*/

$file_name = 'asics_log.csv';

print("\n\nUpload File\n\n");
// Upload an object by streaming the contents of a file
$client->putObject(array(
    'Bucket' => 'rws-portal-global',
    'Key'    => 'asics/' . $file_name,
    'Body'   => fopen($file_name, 'r+')
));

/*
print("\n\nDownload File\n\n");
// Download an object
$result = $client->getObject(array(
    'Bucket' => 'rws-portal-global',
    'Key'    => 'asics' . $file_name,
    'SaveAs' => 'test.txt'
));
*/
?>