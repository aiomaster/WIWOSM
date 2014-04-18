<?php
require('/data/project/wiwosm/WIWOSM/server/class.Wiwosm.php');

if (PHP_SAPI === 'cli') {

error_reporting(E_ERROR);
ini_set('display_errors', true);
ini_set('html_errors', false);

$fullupdate = ($argc > 1) && ($argv[1] == 'full');
$linkupdate = ($argc > 1) && ($argv[1] == 'link');

echo date(DATE_RFC822)."\n";

$wiwosm = new Wiwosm(true, !$linkupdate, 2);

$defaultpath = $wiwosm->json_path;

if ($fullupdate) {
	$wiwosm->json_path = dirname($defaultpath).'/'.date('Y-m-d_H-i').'_geojsongz';
	echo 'doing full update'."\n";
	$wiwosm->createLangTable();
}

if (!$linkupdate) {
	$wiwosm->updateWiwosmDB();
	$wiwosm->logUnknownJSON();
} else {
	$wiwosm->json_path = dirname($defaultpath).'/'.date('Y-m-d_H-i').'_geojsongz';
	echo 'skip DB Update - doing linkupdate only'."\n";
}
$wiwosm->processOsmItems();
if ($fullupdate || $linkupdate) $wiwosm->testAndRename();
}

?>
