<?php
require('/home/master/class.Wiwosm.php');

if (PHP_SAPI === 'cli') {

error_reporting(E_ERROR);
ini_set('display_errors', true);
ini_set('html_errors', false);

$wiwosm = new Wiwosm();

$wiwosm->json_path .= '_update';

$wiwosm->updateWiwosmDB();
$wiwosm->processOsmItems();
$wiwosm->testAndRename();
$wiwosm->exithandler();
}

?>