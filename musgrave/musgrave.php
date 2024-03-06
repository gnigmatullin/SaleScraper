<?php
require 'vendor/autoload.php';
use Aws\S3\S3Client;

date_default_timezone_set('Africa/Cairo');
set_time_limit(0);
error_reporting(E_ALL);

$scraper_log = "musgrave.log";

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

class Musgrave
{
    var $endpoint = 'http://webservices.musgrave.co.za/SyncEAI_Musgrave_Runway/MessageService.svc?wsdl';
    var $user = 'runway';
    var $pass = 'zbA3subLKkD68QhF';
    var $identifier = "D0028172-2F88-4DF5-B4FF-F121CBE85A71";
    var $client;
    var $session_token;

    var $csv_file;
    var $csv_log = "musgrave_log.csv";
    
    var $scrape_result = false;
    var $JobProductIDs = [];
    var $Styles = [];
    var $SKUs = [];
    var $JobProductIDsQty = [];
    var $StylesQty = [];
    var $SKUsQty = [];
    var $sum_qty = 0;
    
    function Musgrave()
    {
        $start_date = @date('Y-m-d');
        $start_time = @date('H:i');
        $start_microtime = microtime(true);
        logg('------------------------');
        logg('Musgrave Scraper started');
        $this->csv_file= "./input/Musgrave_Sync_" . @date('Ymd_hi') . ".csv";
        $this->login();
        $cnt = 0;
        while (!$xml)
        {
            $xml = $this->execute('GetProductStockCatalogue', '<ProductStockCatalogueQueryCriteria xmlns="http://schemas.datacontract.org/2004/07/iSync.EAI.DTO" xmlns:i="http://www.w3.org/2001/XMLSchema-instance"><AddedDate>2000-01-01T00:00:00</AddedDate><AvailableUnits>1</AvailableUnits></ProductStockCatalogueQueryCriteria>');
            $cnt++;
            if ( $cnt >= 4 ) break;
        }
        if (!$xml)
        {
            logg('Error. No XML received from API');
            exit();
        }
        //$xml = file_get_contents('musgrave.xml');
        $this->parse_xml($xml);
        // Upload results to Amazon S3
        $client = new S3Client([
            'version' => 'latest',
            'region'  => 'eu-west-1',
            'credentials' => [
                'key'    => '',
                'secret' => ''
            ]
        ]);
        logg("Upload output file {$this->csv_file} to S3");
        $client->putObject(array(
            'Bucket' => 'rws-portal-global',
            'Key'    => 'musgrave' . str_replace( './', '/', $this->csv_file ),
            'Body'   => fopen($this->csv_file, 'r+')
        ));
        logg("Download log file {$this->csv_log} from S3");
        $result = $client->getObject(array(
            'Bucket' => 'rws-portal-global',
            'Key'    => 'musgrave/' . $this->csv_log,
            'SaveAs' => $this->csv_log
        ));
        
        logg("Write to csv log");
        $csv_log_handle = fopen($this->csv_log, "a");
        if ($this->scrape_result) $result_str = 'Success';
        else $result_str = 'Failed';
        $elapsed_time = ceil( microtime(true) - $start_microtime );
        $scrape_duration = gmdate("H:i:s", $elapsed_time);
        $total_products = count($this->JobProductIDs);
        $total_styles = count($this->Styles);
        $total_skus = count($this->SKUs);
        $total_products_qty = count($this->JobProductIDsQty);
        $total_styles_qty = count($this->StylesQty);
        $total_skus_qty = count($this->SKUsQty);
        $log_line = array(
            $start_date,
            $start_time,
            $scrape_duration,
            $result_str,
            $total_products,
            $total_styles,
            $total_skus,
            $total_products_qty,
            $total_styles_qty,
            $total_skus_qty,
            $this->sum_qty
        );
        fputcsv($csv_log_handle, $log_line);
        fclose($csv_log_handle);

        logg("Upload log file {$this->csv_log} to S3");
        $client->putObject(array(
            'Bucket' => 'rws-portal-global',
            'Key'    => 'musgrave/' . $this->csv_log,
            'Body'   => fopen($this->csv_log, 'r+')
        ));
        logg("-------");
        logg("Summary");
        logg("Scrape Date: $start_date");
        logg("Scrape Time: $start_time");
        logg("Scrape Duration: $scrape_duration");
        logg("Scrape Result: $result_str");
        logg("Total Products Count: $total_products");
        logg("Total Styles Count: $total_styles");
        logg("Total SKUs Count: $total_skus");
        logg("Total Products with Qty Count: $total_products_qty");
        logg("Total Styles with Qty Count: $total_styles_qty");
        logg("Total SKUs with Qty Count: $total_skus_qty");
        logg("Total SKU Qty: {$this->sum_qty}");
        logg("Elapsed time $elapsed_time s");
        logg("Scraper stopped");
    }
    
    // Parse products from xml and save to csv
    function parse_xml($xml_string)
    {
        logg('Parse XML');
        $xml_string = str_replace([':decimal', ':string'], '', $xml_string);
        $xml = new SimpleXMLElement($xml_string);
        $header_written = false;
        // Processing items
        foreach ($xml as $product)
        {
            $header = [];
            $item = [];
            foreach ($product as $key => $value)
            {
                $header[] = $key;
                if (in_array($key, ['CustomFields', 'Prices', 'ProductUDs', 'RetailPrices'])) {
                    foreach ($value as $ind => $val) {
                        $item[$key] = trim($val);
                        break;
                    }
                }
                else $item[$key] = trim($value);
            }
            if (!$header_written)
            {
                $csv = fopen($this->csv_file, "w");
                fputcsv($csv, $header);
                fclose($csv);
                $csv = fopen($this->csv_file, "a");
                $header_written = true;
            }
            if (count($item) > 0)
            {
                if ( !in_array( $item['JobProductID'], $this->JobProductIDs ) )   // Save unique product IDs
                    $this->JobProductIDs[] = $item['JobProductID'];
                if ( !in_array( $item['Style'], $this->Styles ) )   // Save unique product styles
                    $this->Styles[] = $item['Style'];
                if ( !in_array( $item['SKU'], $this->SKUs ) )   // Save unique product SKU
                    $this->SKUs[] = $item['SKU'];
                if ( $item['AvailableUnits'] > 0 )
                {
                    $this->sum_qty++;
                    if ( !in_array( $item['JobProductID'], $this->JobProductIDsQty ) )   // Save unique product IDs
                        $this->JobProductIDsQty[] = $item['JobProductID'];
                    if ( !in_array( $item['Style'], $this->StylesQty ) )   // Save unique product styles
                        $this->StylesQty[] = $item['Style'];
                    if ( !in_array( $item['SKU'], $this->SKUsQty ) )   // Save unique product SKU
                        $this->SKUsQty[] = $item['SKU'];
                }
                fputcsv($csv, $item);
                $this->scrape_result = true;
            }
        }
    }
    
    // Login to SyncAPI
    function login()
    {
        logg('Login to SyncAPI');
        $this->client = new SoapClient($this->endpoint, array('trace' => 1));
        $loginObject = new stdClass();
        $loginObject->username = $this->user;
        $loginObject->password = $this->pass;
        $loginResult = $this->client->Login($loginObject);
        $this->session_token = $loginResult->LoginResult;
        logg('Session token: '.$this->session_token);
    }
    
    // Execute SyncAPI method
    function execute($msg_type, $msg_xml)
    {
        logg('Execute operation: '.$msg_type);
        $Details = new stdClass();
        $Details->Action = 'OTHER';
        $Details->Identifier = $this->identifier;
        $Details->Instance = '1';
        $Details->MessageType = $msg_type;
        $Details->MessageXml = $msg_xml;
        $Details->PublishDate = (new DateTime())->format('c');
        $Details->SessionKey = $this->session_token;
        $Details->Source = 'Magento';
        $Request = new stdClass();
        $Request->request = $Details;
        try
        {
            $result = $this->client->Execute($Request);
        }
        catch (SoapFault $exception)
        {
            logg('SyncAPI Execute error');
            logg(json_encode($exception));
            return false;
        }
        if ($result->Successflag)
        {
            logg('Error in result');
            logg(json_encode($result));
            return false;
        }
        logg('Success');
        $result_xml = $result->ExecuteResult->ResultXml;
        file_put_contents('musgrave.xml', $result_xml);
        return $result_xml;
    }

}

$musgrave = new Musgrave();

?>