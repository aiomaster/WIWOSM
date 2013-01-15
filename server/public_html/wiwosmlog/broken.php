<?php
header('Access-Control-Allow-Origin: *');

header('Content-Encoding: gzip');
readfile('broken.json.gz');

?>