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

run_scraper('forevernew', 'new-in');
run_scraper('forevernew', 'dresses');
run_scraper('forevernew', 'clothing');
run_scraper('forevernew', 'accessories');
run_scraper('forevernew', 'shoes');
run_scraper('forevernew', 'sale');

?>