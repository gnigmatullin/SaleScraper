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
    file_put_contents('get_images.log', $log , FILE_APPEND | LOCK_EX);
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

function parse_image_urls($html)
{
    global $skus;
    
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $files = [];
    $colors_found = false;
    foreach ($xpath->query('//a[ @data-attribute-type="color" ]') as $node) {
        foreach ($xpath->query('./img', $node) as $img_node) {
            $image_url = trim($img_node->getAttribute('src'));
            $image_url = preg_replace('#\.jpg.*?$#', '.jpg', $image_url);
            for ($i = 1; $i <= 5; $i++) {
                $image_url = preg_replace('#\d\.jpg#', $i.'.jpg', $image_url);
                $file_name = regex('#([\d\-]+\.jpg)#', $image_url, 1);
                if (!in_array($file_name, $files))
                {
                    logg("Load image URL: $image_url");
                    $sku = regex('#([\d\-]+?)\-\d+\.jpg#', $image_url, 1);
                    logg("SKU: ".$sku);
                    if (in_array($sku, $skus))
                    {
                    
                        $files[] = $file_name;
                        $image = file_get_contents($image_url);
                        if ($image != false) {
                            logg("Save to file: ".$file_name);
                            file_put_contents('./images/'.$file_name, $image);
                        }
                        else
                            logg("No image found");
                    }
                    else
                        logg("SKU not found in sku.txt");
                }
            }
        }
        //break;
        $colors_found = true;
    }
    if (!$colors_found) {
        foreach ($xpath->query('//div[ @id="thumbnails" ]/ul/li/a[ @class="thumbnail-link" ]') as $node) {
            foreach ($xpath->query('./img', $node) as $img_node) {
            $image_url = trim($img_node->getAttribute('src'));
            $image_url = preg_replace('#\.jpg.*?$#', '.jpg', $image_url);
            for ($i = 1; $i <= 5; $i++) {
                $image_url = preg_replace('#\d\.jpg#', $i.'.jpg', $image_url);
                $file_name = regex('#([\d\-]+\.jpg)#', $image_url, 1);
                if (!in_array($file_name, $files))
                {
                    logg("Load image URL: $image_url");
                    $sku = regex('#([\d\-]+?)\-\d+\.jpg#', $image_url, 1);
                    logg("SKU: ".$sku);
                    if (in_array($sku, $skus))
                    {
                    
                        $files[] = $file_name;
                        $image = file_get_contents($image_url);
                        if ($image != false) {
                            logg("Save to file: ".$file_name);
                            file_put_contents('./images/'.$file_name, $image);
                        }
                        else
                            logg("No image found");
                    }
                    else
                        logg("SKU not found in sku.txt");
                }
            }
        }
        }
    }
}

unlink('get_images.log');
$handle = fopen("sku.txt", "r");
$skus = [];
if ($handle) {
    while (($sku = fgets($handle)) !== false) {
        $skus[] = trim($sku);
    }
}
fclose($handle);
var_dump($skus);

foreach ($skus as $sku) {
    $url = 'https://cottonon.com/ZA/'.trim($sku).'.html';
    //$url = 'https://cottonon.com/ZA/reese-tie-front-cami/2006359-05.html';
    //$url = 'https://cottonon.com/ZA/workout-yoga-crop/660540-112.html';
    logg("Load $url");
    $html = curl($url);
    parse_image_urls($html);
    //break;
}
