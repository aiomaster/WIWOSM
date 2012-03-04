<?php
 
// set the charset to utf-8 -- all data in OSM ist stored as utf-8
header('Content-Type: text/html; charset=utf-8');
 
// bbox of mainz
$bbox = array(8.11, 50.07, 8.46, 49.91);
 
#use pg_escape_string() against sql-injection if you have input

// open psql connection
$pg = pg_connect('host=sql-mapnik dbname=osm_mapnik');
 
// check for connection error
if($e = pg_last_error()) die($e);
 
// select all streets with a name in bbox
$sql = 'SELECT DISTINCT lang,article FROM wiwosm 
        Where ST_length(way)>0 and article not like \'%/%\'
	LIMIT 2000';

//WHERE way &&  ST_Transform(ST_SetSRID(ST_MakeBox2D(
//			ST_Point('.floatval($bbox[0]).','.floatval($bbox[1]).'), 
//			ST_Point('.floatval($bbox[2]).','.floatval($bbox[3]).')), 
//		4326),900913)
 
// query the database
$res = pg_query($sql);
 
// check for query error
if($e = pg_last_error()) die($e);
 
// produce some output
echo "<ul>\n";
while($row = pg_fetch_assoc($res))
{
	echo '<li>'.htmlspecialchars($row['lang']).': <a href="//toolserver.org/~kolossos/openlayers/kml-on-ol-json3.php?lang='.$row['lang'].'&title='.$row['article'].'">';
	echo htmlspecialchars($row['article']);
	echo "</a></li>\n";
}
echo "</ul>";
 
?> 
