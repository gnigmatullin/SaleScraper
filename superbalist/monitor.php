<?php
ini_set('error_reporting', E_ERROR);

function write_log($msg)
{
	$f=fopen('monitor.log','a');
	fwrite($f, @date("H:i:s",time()).' : '.$msg."\r\n");
	fclose($f);
}

function run_scraper($source, $from_page)
{
    write_log("run $source from page $from_page");
    //exec("nohup php $source.php --from_page=$from_page --max_pages=100 > /dev/null 2>&1 &");
    exec("nohup php $source.php > /dev/null 2>&1 &");
}

run_scraper('superbalist_api', 1);
/*run_scraper('superbalist', 101);
run_scraper('superbalist', 201);
run_scraper('superbalist', 301);
run_scraper('superbalist', 401);
run_scraper('superbalist', 501);*/

?>