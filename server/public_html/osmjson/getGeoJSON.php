<?php
//error_reporting(E_ERROR);
ini_set('display_errors', false);
ini_set('html_errors', false);

require('/home/master/class.Wiwosm.php');

$article = $_GET['article'];
$lang = $_GET['lang'];

$wiwosm = new Wiwosm();

if ($_GET['action']=='purge' && $article && $lang) {

	// no output please
	ob_start();
	$wiwosm->updateOneObject($lang,$article);
	ob_end_clean();
}

$file = $wiwosm->getFilePath($lang,$article);
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