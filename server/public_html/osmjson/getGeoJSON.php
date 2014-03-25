<?php
require('/home/master/class.Wiwosm.php');

//error_reporting(E_ERROR);
ini_set('display_errors', false);
ini_set('html_errors', false);

header('Access-Control-Allow-Origin: *');

$wiwosm = new Wiwosm(false);

$article = $_GET['article'];
$lang = $_GET['lang'];

// status check for multiple, comma-separated articles
if ($_GET['action'] == 'check' && $lang && $_REQUEST['articles']) {
	header('Content-Encoding: text/plain');
	$articles = explode(',', $_REQUEST['articles']);
	foreach ($articles as $article) {
		$file = $wiwosm->getFilePath($lang, $article, false);
		print "$article\t" . (file_exists($file) ? 1 : 0) . "\n";
	}
	exit();
}

if ($_GET['action']=='purge' && $article && $lang) {
	if ($lang == 'wikidata') {
		echo 'update of wikidata objects in WIWOSM is not possible at the moment. Sorry!';
		exit();
	}

	$wiwosm->openPgConnection();
	$wiwosm->updateOneObject($lang,$article);
}

$file = $wiwosm->getFilePath($lang,$article,false);
if (file_exists($file)) {
	if ($_GET['action']=='check') {
		echo 1;
	} else {
		header('Content-Encoding: gzip');
		readfile($file);
	}
} else {
	if ($_GET['action']=='check') {
		echo 0;
	} else {
		header("HTTP/1.0 404 Not Found");
	}
}
