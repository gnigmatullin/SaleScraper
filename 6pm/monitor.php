<?php
ini_set('error_reporting', E_ERROR);

function write_log($msg)
{
	$f=fopen('monitor.log','a');
	fwrite($f, @date("H:i:s",time()).' : '.$msg."\r\n");
	fclose($f);
}

function run_scraper($source, $category)
{
    write_log("run $source $category");
    exec("nohup php $source.php $category > /dev/null 2>&1 &");
}

// Shoes subcats
run_scraper('6pm', 'sneakers-athletic-shoes/CK_XARC81wHiAgIBAg'); //20
run_scraper('6pm', 'sandals/CK_XARC51wHiAgIBAg'); //18
run_scraper('6pm', 'boots/CK_XARCz1wHiAgIBAg'); //16
run_scraper('6pm', 'heels/CK_XARC41wHiAgIBAg'); //14
run_scraper('6pm', 'flats/CK_XARC11wHiAgIBAg,loafers/CK_XARC21wHiAgIBAg,oxfords/CK_XARC31wHiAgIBAg,clogs-mules/CK_XARC01wHiAgIBAg'); //5, 4, 2, 2
// Clothing subcats
run_scraper('6pm', 'shirts-tops/CKvXARDL1wHiAgIBAg');   //16
run_scraper('6pm', 'dresses/CKvXARDE1wHiAgIBAg,coats-outerwear/CKvXARDH1wHiAgIBAg');   //9, 6
run_scraper('6pm', 'swimwear/CKvXARDR1wHiAgIBAg,pants/CKvXARDK1wHiAgIBAg');  //5, 4
run_scraper('6pm', 'jeans/CKvXARDI1wHiAgIBAg,shorts/CKvXARDM1wHiAgIBAg,hoodies-sweatshirts/CKvXARDF1wHiAgIBAg,sweaters/CKvXARDQ1wHiAgIBAg,underwear-intimates/CKvXARDG1wHiAgIBAg,skirts/CKvXARDN1wHiAgIBAg'); //2, 2, 2, 2, 1, 1
run_scraper('6pm', 'bags/COjWAeICAQE,accessories/COfWAeICAQE,eyewear/CKzXAeICAQE,jewelry/CK7XAeICAQE'); //5, 2, 1, 1

?>