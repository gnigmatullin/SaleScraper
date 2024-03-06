<?php
set_time_limit(0);
error_reporting(E_ERROR);

function regex($regex, $input, $output = 0)
{
    $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
    if (!$match)
        $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
    return $match;
}

function logg($text, $error_level = 0)
{
    switch ($error_level)
    {
        case 0: $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL; break;
        case 1: $log = '[' . @date('Y-m-d H:i:s') . '] WARNING: ' . $text . PHP_EOL; break;
        case 2: $log = '[' . @date('Y-m-d H:i:s') . '] ERROR: ' . $text . PHP_EOL; break;
        default: $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL; break;
    }
    echo $log;
    file_put_contents('get_products.log', $log , FILE_APPEND | LOCK_EX);
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

function filter_text($text)
{
    $text = preg_replace('#[^\w^\d^\.^\'^\,]#', ' ', $text);
    $text = preg_replace('#\s+#', ' ', $text);
    $text = preg_replace('#[\r\n]#', ' ', $text);
    $text = trim($text);
    return $text;
}

function parse_text($html)
{
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $res = [];
    foreach ($xpath->query('//h1[ @itemprop = "name" ]') as $node) {
        $res['name'] = filter_text($node->nodeValue);
        break;
    }
    foreach ($xpath->query('//div[ @class = "product-content" ]') as $node) {
        $res['description'] = filter_text($node->nodeValue);
        break;
    }
    foreach ($xpath->query('//div[ ./h2[contains(., "Product Dimensions")] ]') as $node) {
        $res['description'] .= ' '.filter_text($node->nodeValue);
        break;
    }
	foreach ($xpath->query('//div[ @class = "product-price" ]') as $node) {
        $res['price'] = filter_text($node->nodeValue);
        break;
    }
    return $res;
}

unlink('get_products.log');
$handle = fopen("sku.txt", "r");

$file_name = 'results.csv';
logg('Write into CSV: '.$file_name);
$file = fopen($file_name, "w");
if (!$file)
{
    logg("Unable to open file ".$file_name);
    exit();
}
$header = ['SKU', 'Product name', 'Product description', 'Product price'];
fputcsv($file, $header);

$countries = ['ZA'/*, 'AU', 'NZ', 'HK', 'MY', 'SG', 'US'*/];

if ($handle) {
    while (($sku = fgets($handle)) !== false) {
        foreach ($countries as $country) {
            $url = 'https://cottonon.com/'.$country.'/'.trim($sku).'.html';
            logg("Load $url");
            $html = curl($url);
            if ($html == -1) continue;  // try next country
            $res = parse_text($html);
            $arr = [];
            $arr[] = trim($sku);
            $arr[] = $res['name'];
            $arr[] = $res['description'];
			$arr[] = $res['price'];
            fputcsv($file, $arr);
            break;
        }
        //break;
    }
    fclose($handle);
}