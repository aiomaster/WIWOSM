<?php
require('/home/master/class.Wiwosm.php');

if (PHP_SAPI === 'cli') {

error_reporting(E_ERROR);
ini_set('display_errors', true);
ini_set('html_errors', false);

$fullupdate = ($argc > 1) && ($argv[1] == 'full');

echo date(DATE_RFC822)."\n";

$wiwosm = new Wiwosm();

if ($fullupdate) {
	$wiwosm->json_path .= '_update';
	echo 'doing full update'."\n";
	$wiwosm->createLangTable();
}

$wiwosm->updateWiwosmDB();
$wiwosm->logUnknownJSON();
$wiwosm->processOsmItems();
if ($fullupdate) $wiwosm->testAndRename();
$wiwosm->exithandler();
}

?>