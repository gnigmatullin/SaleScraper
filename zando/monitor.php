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

run_scraper('zando', 'women/clothing');
run_scraper('zando', 'women/shoes');
run_scraper('zando', 'women/accessories');
run_scraper('zando', 'women/beauty');
run_scraper('zando', 'women/sports');
run_scraper('zando', 'men/clothing');
run_scraper('zando', 'men/shoes');
run_scraper('zando', 'men/accessories');
run_scraper('zando', 'men/sports');
run_scraper('zando', 'men/beauty');
run_scraper('zando', 'kids/clothing');
run_scraper('zando', 'kids/shoes');
run_scraper('zando', 'kids/accessories');
run_scraper('zando', 'kids/beauty');
?>