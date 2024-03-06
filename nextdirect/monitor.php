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

run_scraper('nextdirect', 'gender-women-productaffiliation-clothing');
run_scraper('nextdirect', 'gender-women-productaffiliation-footwear');
run_scraper('nextdirect', 'gender-women-productaffiliation-accessories');
run_scraper('nextdirect', 'gender-women-productaffiliation-lingerie');
run_scraper('nextdirect', 'gender-men-productaffiliation-clothing');
run_scraper('nextdirect', 'gender-men-productaffiliation-underwear');
run_scraper('nextdirect', 'gender-men-productaffiliation-accessories');
run_scraper('nextdirect', 'gender-men-productaffiliation-footwear');
run_scraper('nextdirect', 'gender-newborngirls');
run_scraper('nextdirect', 'gender-youngergirls');
run_scraper('nextdirect', 'gender-oldergirls');
run_scraper('nextdirect', 'gender-newbornunisex');
run_scraper('nextdirect', 'gender-newbornboys');
run_scraper('nextdirect', 'gender-youngerboys');
run_scraper('nextdirect', 'gender-olderboys');

?>