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
    exec("nohup php $source.php --category=$category > /dev/null 2>&1 &");
}

run_scraper('cocosa', 'women-clothing/V29tZW4_Pj5DbG90aGluZw==');
run_scraper('cocosa', 'women-accessories/V29tZW4_Pj5BY2Nlc3Nvcmllcw==');
run_scraper('cocosa', 'women-footwear/V29tZW4_Pj5Gb290d2Vhcg==');
run_scraper('cocosa', 'men-clothing/TWVuPj4_Q2xvdGhpbmc=');
run_scraper('cocosa', 'men-accessories/TWVuPj4_QWNjZXNzb3JpZXM=');
run_scraper('cocosa', 'men-footwear/TWVuPj4_Rm9vdHdlYXI=');
run_scraper('cocosa', 'kids-and-toys-clothing/S2lkcyAmIFRveXM_Pj5DbG90aGluZw==');
run_scraper('cocosa', 'kids-and-toys-shoes/S2lkcyAmIFRveXM_Pj5TaG9lcw==');

?>