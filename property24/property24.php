<?php
error_reporting(E_ERROR);

function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents("property24.log", $log , FILE_APPEND | LOCK_EX);
}

// Run regexp on a string and return the result
function regex( $regex, $input, $output = 0 )
{
    $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
    if (!$match)
        $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
    return $match;
}

function get_page ($url)
{
    $proxies = ['de', 'us', 'us-dc', 'uk', 'ch', 'world'];
    $num = rand(0, count($proxies) - 1);
    $proxy = $proxies[$num];
    logg("Proxy: $proxy");
    $options = array (
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.186 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 10,
    );
    logg("Get page $url");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt_array($ch, $options);
    $html = curl_exec($ch);
    //var_dump($html);
    if(!curl_errno($ch))
    {
        $info = curl_getinfo($ch);
        logg("Response code ".$info['http_code']);
        if ( $info['http_code'] != 200 )
            return -1;
        //var_dump ($info);
    }
    else
    {
        $err = curl_error( $ch );
        logg("CURL error ".curl_errno($ch)." ".$err);
        return -1;
    }
    curl_close($ch);
    return $html;
}

function load_page($url)
{
    $i = 0;
    $html = -1;
    while ($html == -1)
    {
        $html = get_page($url);
        $i++;
        if ($i > 4) break;
    }
    if ($html == -1) logg('Load page error');
    return $html;
}

function cleantags( $input, $flatten = false, $extratags = [], $strip_bytes = false )
{
    if ( empty( $input ) || !is_array( $extratags ) )
        return false;
    // Remove/convert broken quotation marks
    $output = preg_replace( '/(&#14[5-8];)/', '"', $input );
    // Decode &auml;'s to regular characters
    $output = html_entity_decode( $output, ENT_COMPAT, 'UTF-8' );
    // Secondary specialchar decoder
    $output = htmlspecialchars_decode( $output, ENT_QUOTES );
    // Set line feed to literal \n or ' '
    $output = preg_replace( '#[\x0A\x0D]#', $flatten ? ' ' : '\n', $output );
    // Remove non-utf8 characters, can remove åäö
    if ( $strip_bytes )
        $output = preg_replace( '#[\x00-\x1F\x80-\xFF]#', '', $output );
    // Convert extratags into regex-split if present
    $xtags = ( !empty( $extratags ) ? '|' . implode( '|', $extratags ) : '' );
    // Replace <br>, </div>, </p>, </div> and </li> with literal \n (not the char) or ' ' and replace multiple spaces to single space
    if ( !$flatten )
        $output = preg_replace( [
            '#[\s\n\\n]*<[/\s]?(br|/p|/div|/li' . $xtags . ')\s*/?\s*>[\s\n\\n]*#iu',
            '#\s+#'
        ], [
            '\n',
            ' '
        ], $output );
    else
        $output = preg_replace( [
            '#[\s\n\\n]*<[/\s]?(br|/p|/div|/li' . $xtags . ')\s*/?\s*>[\s\n\\n]*#iu',
            '#\s+#'
        ], ' ', $output );
    // Strip tags
    $output = strip_tags( $output );
    // Replace more than two \n to \n\n.
    $output = preg_replace( '#(\\\\n|\\\n|\\n|\n){3,}#ui', '', $output );
    // Remove all spaces and \n from beginning and end
    $output = preg_replace( [
        '#^\s+#u',
        '#^(\n)+|^(\\n)+|^(\\\n)+|^(\\\\n)+#iu',
        '#(\n)+$|(\\n)+$|(\\\n)+$|(\\\\n)+$#iu'
    ], [ '' ], $output );
    $output = str_replace('\n\n', '', $output);
    return trim($output);
}

// Parse catalog html
function parse_catalog($url)
{
    $html = load_page($url);
    if ($html == -1) return false;
    logg('Parse catalog html');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    // Get pagination
    $nodes = $x->query('//ul[ @class = "pagination" ]/li[ last() ]/a');
    foreach ( $nodes as $node )
    {
        $pagination = $node->nodeValue;
        break;
    }
    if (!isset($pagination)) $pagination = 1;
    logg($pagination . ' pages found');
    //parse_catalog_page($html);
    
    for ($page = 2; $page <= $pagination; $page++)
    {
        logg('Parse catalog page ' . $page);
        $page_html = load_page($url . '/p' . $page);
        parse_catalog_page($page_html);
    }
}

// Parse item links from catalog page
function parse_catalog_page($html)
{
    if ($html == -1) return false;
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    $links = array();
    foreach ( $x->query('//div[ @class = "js_listingResultsContainer" ]//a[ contains(@href, "/for-sale/") ]') as $node )
    {
        $links[] = $node->getAttribute("href");
    }
    $links = array_unique($links); 
    logg(count($links).' links found');
    foreach ($links as $link)
    {
        parse_item('https://www.property24.com' . $link);
    }
}

function parse_item($url)
{
    $html = load_page($url);
    if ($html == -1) return false;
    logg('Parse item html');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    $item = [];
    $nodes = $x->query('//h1');
    foreach ( $nodes as $node )
    {
        $item['Title'] = trim($node->nodeValue);
        break;
    }
    if (!isset($item['Title']))
    {
        logg('Title not found. Skip item');
        return;
    }
    if ($item['Title'] == 'Listing Not Found')
    {
        logg('Title not found. Skip item');
        return;
    } 
    $nodes = $x->query('//div[ ./h1 ]');
    foreach ( $nodes as $node )
    {
        $item['Region'] = $node->nodeValue;
        $item['Region'] = trim(str_replace($item['Title'], '', $item['Region']));
        break;
    }
    if (!isset($item['Region'])) $item['Region'] = '';
    $nodes = $x->query('//div[ @class = "p24_price" ]');
    foreach ( $nodes as $node )
    {
        $item['Price'] = $node->nodeValue;
        $item['Price'] = preg_replace('#[^\d]#', '', $item['Price']);
        break;
    }
    if (!isset($item['Price'])) $item['Price'] = '';
    $nodes = $x->query('//div[ @class = "js_readMore" ]');
    foreach ( $nodes as $node )
    {
        $item['Full Description'] = cleantags($node->nodeValue, true);
        break;
    }
    if (!isset($item['Full Description'])) $item['Full Description'] = '';
    $nodes = $x->query('//h5[ contains(., "Agency") ]/following-sibling::a/img');
    foreach ( $nodes as $node )
    {
        $item['Agency'] = $node->getAttribute('alt');
        $item['Agency'] = str_replace('Property for sale by ', '', $item['Agency']);
        break;
    }
    if (!isset($item['Agency'])) $item['Agency'] = '';
    $nodes = $x->query('//div[ @class = "p24_agentPhoto" ]/a/span');
    foreach ( $nodes as $node )
    {
        $item['Agent'] = $node->nodeValue;
        break;
    }
    if (!isset($item['Agent'])) $item['Agent'] = '';
    $nodes = $x->query('//div[ @class = "p24_bond" ]/h5[ contains(., "Bond Costs") ]/following-sibling::h4');
    foreach ( $nodes as $node )
    {
        $item['Bond Costs'] = preg_replace('#[^\d]#', '', $node->nodeValue);
        break;
    }
    if (!isset($item['Bond Costs'])) $item['Bond Costs'] = '';
    $nodes = $x->query('//div[ @class = "p24_bond" ]/div[ contains(., "Interest rate") ]/following-sibling::div');
    foreach ( $nodes as $node )
    {
        $item['Interest rate'] = $node->nodeValue;
        break;
    }
    if (!isset($item['Interest rate'])) $item['Interest rate'] = '';
    $nodes = $x->query('//div[ @class = "p24_bond" ]/div[ contains(., "Period") ]/following-sibling::div');
    foreach ( $nodes as $node )
    {
        $item['Period'] = $node->nodeValue;
        break;
    }
    if (!isset($item['Period'])) $item['Period'] = '';
    $nodes = $x->query('//div[ @class = "p24_bond" ]/div[ contains(., "Deposit") ]/following-sibling::div');
    foreach ( $nodes as $node )
    {
        $item['Deposit'] = $node->nodeValue;
        break;
    }
    if (!isset($item['Deposit'])) $item['Deposit'] = '';
    $rows = $x->query('//div[ @class = "p24_features" ]/div');
    $keys = [];
    $values = [];
    foreach ( $rows as $row )
    {
        $cols = $x->query('./div', $row);
        $i = 0;
        foreach ( $cols as $col )
        {
            if ($i == 0) $keys[] = trim($col->nodeValue);
            else $values[] = cleantags($col->nodeValue);
            $i++;
        }
    }
    //var_dump($keys);
    //var_dump($values);
    $i = 0;
    $allowed_properties = ['Type of Property', 'Description', 'Erf Size', 'Floor Size', 'Levies', 'Rates and Taxes', 'Listing Date', 'Bedroom', 'Bathroom', 'Reception Room', 'Office/study', 'Domestic', 'Dining Room', 'Kitchen', 'Lounge', 'Garage', 'Garden', 'Parking', 'Pool', 'Security', 'Special Feature', 'Pets Allowed'];
    $numeric_properties = ['Erf Size', 'Floor Size', 'Levies', 'Rates and Taxes'];
    foreach ($keys as $key)
    {
        $key = str_replace(['Bedrooms', 'Bathrooms', 'Reception Rooms', 'Domestics', 'Dining Rooms', 'Kitchens', 'Lounges', 'Garages', 'Gardens', 'Parkings', 'Pools'], ['Bedroom', 'Bathroom', 'Reception Room', 'Domestic', 'Dining Room', 'Kitchen', 'Lounge', 'Garage', 'Garden', 'Parking', 'Pool'], $key);
        if (!in_array($key, $allowed_properties)) 
        {
            $i++;
            continue;
        }
        if (in_array($key, $numeric_properties))
            $item[$key] = regex('#([\d\.\,]+)#', $values[$i]);
        else
            $item[$key] = $values[$i];
        $i++;
    }
    foreach ($allowed_properties as $prop)
    {
        if (!isset($item[$prop])) $item[$prop] = '';
    }
    $nodes = $x->query('//a[ @class = "js_pp_image js_lightbox_image_src" ]');
    $i = 1;
    foreach ( $nodes as $node )
    {
        $item['Image'.$i] = $node->getAttribute('data-lightbox-src');
        $i++;
        if ($i > 5) break;
    }
    for ($i = 1; $i <= 5; $i++)
        if (!isset($item['Image'.$i])) $item['Image'.$i] = '';
    // Points of Interest
    $pofint_url = 'https://www.property24.com' . regex('#(\/ListingReadOnly\/PointsOfInterestView\?Latitude=[\-\d\.]+&Longitude=[\-\d\.]+)#', $html);
    logg('Points of Interest URL: ' . $pofint_url);
    $item['Points of Interest'] = pofint_parse($pofint_url);

    ksort($item);
    $item['URL'] = $url;
    //var_dump($item);
    write_item($item);
    sleep(5);
}

// Points of interest parse
function pofint_parse($url)
{
    $html = load_page($url);
    if ($html == -1) return '';
    logg('Parse point of interest html');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $x = new DOMXPath($dom);
    $rows = $x->query('//div[ @class = "row" ]');
    $keys = [];
    $values = [];
    foreach ( $rows as $row )
    {
        $cols = $x->query('./div', $row);
        if ($cols->length != 2) continue;
        $i = 0;
        foreach ( $cols as $col )
        {
            if ($i == 0) $keys[] = trim($col->nodeValue);
            else $values[] = trim($col->nodeValue);
            $i++;
        }
    }
    $i = 0;
    $output = '';
    foreach ($keys as $key)
    {
        if ( strpos($values[$i], 'km') !== false )
        {
            $output .= $key . ' ' . $values[$i] . '; ';
        }
        $i++;
    }
    return $output;
}

function write_item($item)
{
    logg('Write item to csv');
    $dump = '';
    foreach ( $item as $key => $value )
        $dump .= $key . ': ' . $value . ' ';
    logg($dump);
    if (!file_exists('property24.csv'))
    {
        $handle = fopen('property24.csv', "w");
        $header = array_keys($item);
        fputcsv($handle, $header);
        fputcsv($handle, $item);
    }
    else
    {
        $handle = fopen('property24.csv', "a");
        fputcsv($handle, $item);
    }
}

file_put_contents("property24.log", "");
logg('---------------');
logg('Scraper started');
parse_catalog('https://www.property24.com/for-sale/cape-town/western-cape/432');
//parse_item('https://www.property24.com/for-sale/lakeside/cape-town/western-cape/10174/106260610?plId=216322&plt=3');
logg("Scraper stopped");