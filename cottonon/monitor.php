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

run_scraper('cottonon', 'co/women');
run_scraper('cottonon', 'co/men');
run_scraper('cottonon', 'co/collab-shop');
run_scraper('cottonon', 'co/co-gifts');
run_scraper('cottonon', 'kids');
run_scraper('cottonon', 'typo');
run_scraper('cottonon', 'factorie');

?>