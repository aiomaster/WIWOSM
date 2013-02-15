<?php
//error_reporting(E_ERROR);
ini_set('display_errors', false);
ini_set('html_errors', false);

header('Access-Control-Allow-Origin: *');

// TODO: Refactor the filepath functions here and in class.Wiwosm.php

define('JSON_PATH','/mnt/user-store/wiwosm/geojsongz');

/**
 * fnvhash funtion copied from http://code.google.com/p/boyanov/wiki/FNVHash
 * @param string $txt Input text to hash 
 * @return string FNVHash of text 
 **/
function fnvhash($txt) {
	$buf = str_split($txt);
	$hash = 16777619;
	foreach ($buf as $chr)
	{
		$hash += ($hash << 1) + ($hash << 4) + ($hash << 7) + ($hash << 8) + ($hash << 24);
		$hash = $hash ^ ord($chr);
	}
	$hash = $hash & 0x0ffffffff;
	return sprintf("%X", $hash);
}

/**
 * This function computes a filepath for a given lang/article combination
 * so that a good distributed tree will result in the filesystem.
 * @param string $lang the language of the given article
 * @param string $article the name of the article
 * @return string the absolute filepath for this lang and article 
 **/
function getFilePath($lang, $article) {
	$article = str_replace('_',' ',$article);
	$hash = fnvhash($lang.$article);
	return JSON_PATH . '/'.substr($hash,0,2).'/'.substr($hash,0,4).'/'.$hash.'_'.substr(str_replace(array("\0",'/'),array('','-'),$lang.'_'.$article),0,230).'.geojson.gz';
}



$article = $_GET['article'];
$lang = $_GET['lang'];

// status check for multiple, comma-separated articles
if ($_GET['action'] == 'check' && $lang && $_REQUEST['articles']) {
	header('Content-Encoding: text/plain');
	$articles = explode(',', $_REQUEST['articles']);
	foreach ($articles as $article) {
		$file = getFilePath($lang, $article);
		print "$article\t" . (file_exists($file) ? 1 : 0) . "\n";
	}
	exit();
}

if ($_GET['action']=='purge' && $article && $lang) {
	require('/home/master/class.Wiwosm.php');
	$wiwosm = new Wiwosm();
	// no output please
	ob_start();
	$wiwosm->updateOneObject($lang,$article);
	ob_end_clean();
}

$file = getFilePath($lang,$article);
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

?>