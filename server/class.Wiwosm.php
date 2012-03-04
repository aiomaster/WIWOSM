<?php
class Wiwosm {

	private $toolserver_mycnf;

	//$alllang = array('aa','ab','ace','af','ak','als','am','an','ang','ar','arc','arz','as','ast','av','ay','az','ba','bar','bat-smg','bcl','be','be-x-old','bg','bh','bi','bjn','bm','bn','bo','bpy','br','bs','bug','bxr','ca','cbk-zam','cdo','ce','ceb','ch','cho','chr','chy','ckb','co','cr','crh','cs','csb','cu','cv','cy','cz','da','de','diq','dk','dsb','dv','dz','ee','el','eml','en','eo','epo','es','et','eu','ext','fa','ff','fi','fiu-vro','fj','fo','fr','frp','frr','fur','fy','ga','gag','gan','gd','gl','glk','gn','got','gu','gv','ha','hak','haw','he','hi','hif','ho','hr','hsb','ht','hu','hy','hz','ia','id','ie','ig','ii','ik','ilo','io','is','it','iu','ja','jbo','jp','jv','ka','kaa','kab','kbd','kg','ki','kj','kk','kl','km','kn','ko','koi','kr','krc','ks','ksh','ku','kv','kw','ky','la','lad','lb','lbe','lg','li','lij','lmo','ln','lo','lt','ltg','lv','map-bms','mdf','mg','mh','mhr','mi','minnan','mk','ml','mn','mo','mr','mrj','ms','mt','mus','mwl','my','myv','mzn','na','nah','nan','nap','nb','nds','nds-nl','ne','new','ng','nl','nn','no','nov','nrm','nv','ny','oc','om','or','os','pa','pag','pam','pap','pcd','pdc','pfl','pi','pih','pl','pms','pnb','pnt','ps','pt','qu','rm','rmy','rn','ro','roa-rup','roa-tara','ru','rue','rw','sa','sah','sc','scn','sco','sd','se','sg','sh','si','simple','sk','sl','sm','sn','so','sq','sr','srn','ss','st','stq','su','sv','sw','szl','ta','te','tet','tg','th','ti','tk','tl','tn','to','tpi','tr','ts','tt','tum','tw','ty','udm','ug','uk','ur','uz','ve','vec','vi','vls','vo','wa','war','wo','wuu','xal','xh','xmf','yi','yo','za','zea','zh','zh-cfr','zh-classical','zh-min-nan','zh-yue','zu');

	const simplifyGeoJSON = 'ST_AsGeoJSON(
		CASE
			WHEN ST_NPoints(ST_Collect(way))<10000 THEN ST_Collect(way)
			WHEN ST_NPoints(ST_Collect(way))>10000 AND ST_NPoints(ST_Collect(way))<20000 THEN ST_Simplify(ST_Collect(way),20)
			ELSE ST_Simplify(ST_Collect(way),150)
		END
	,9) AS geojson';

	const JSON_PATH = '/mnt/user-store/master/geojsongz';
	var $json_path;

	private $conn;

	private $start;

	function __construct() {
		$this->start = microtime(true);
		$this->json_path = self::JSON_PATH;
		$this->toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
		$this->openPgConnection();
	}

	function openPgConnection() {
		// open psql connection
		$this->conn = pg_connect('host=sql-mapnik dbname=osm_mapnik');
		//$this->conn = pg_connect('host=localhost port=5432 dbname=osm user=master');
		// check for connection error
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
	}

	/**
	 * fnvhash funtion copied from http://code.google.com/p/boyanov/wiki/FNVHash
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

	function getFilePath($lang, $article) {
		//$hash = md5($lang.str_replace('_',' ',$article));
		// use fnvhash because its much faster than md5
		$hash = $this->fnvhash($lang.str_replace('_',' ',$article));
		$path = $this->json_path.'/'.substr($hash,0,2).'/'.substr($hash,0,4);
		mkdir($path, 0755, true);
		$path .= '/'.$hash.'.geojson.gz';
		unset($hash);
		return $path;
	}

	function logUnknownLang($l,$a) {
		error_log($l."\t".$a."\n",3,'/home/master/unknown.csv');
	}

	function testAndRename() {
		//$countFiles = system('ls -RU1 --color=never '.$json_path.' | wc -l');
		echo 'Counting generated files …'."\n";
		$countFiles = system('find '.$this->json_path.' -type f | wc -l');
		// if there are more than 500000
		if ( $countFiles > 500000 ) {
			rename(self::JSON_PATH , self::JSON_PATH . '_old');
			rename($this->json_path , self::JSON_PATH );
		}
	}

	function exithandler() {
		echo 'Execution time: '.((microtime(true)-$this->start)/60)."min\n";
		pg_close($this->conn);
		exit;
	}

	function updateWiwosmDB() {
$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm;
CREATE TABLE wiwosm AS (
SELECT osm_id, way, split_part(wikipedia, ':', 1) AS lang, split_part(split_part(wikipedia, ':', 2),'#', 1) AS article, split_part(wikipedia,'#', 2) AS anchor FROM (
SELECT osm_id, way,
regexp_replace(
  substring(
    concat(
      substring(array_to_string(akeys(tags),',') from 'wikipedia:?[^,]*'), -- this is the tagname for example "wikipedia" or "wikipedia:de"
      ':',
      regexp_replace(
        tags->substring(array_to_string(akeys(tags),',') from '[^,]*wikipedia:?[^,]*'), -- get the first wikipedia tag from hstore
        E'^http[s]?://(\\w*)\\.wikipedia\\.org/wiki/(.*)$', -- matches if the value is a wikipedia url (otherwise it is an article)
        '\1:\2' -- get the domain prefix and use it as language key followed by the article name
      )
    ) -- resulting string is for example wikipedia:de:Dresden
    from 11 -- remove the "wikipedia:" prefix
  ),
  E'^(\\w*:)\\1','\1' -- it is possible that there is such a thing like "de:de:Artikel" left if there was a tag like "wikipedia:de=http://de.wikipedia.org/wiki/Artikel", so remove double language labels
) AS "wikipedia"
FROM (
( SELECT osm_id, tags, way FROM planet_point WHERE strpos(array_to_string(akeys(tags),','),'wikipedia')>0 )
UNION ( SELECT osm_id, tags, way FROM planet_line WHERE strpos(array_to_string(akeys(tags),','),'wikipedia')>0 AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) ) -- we don't want LineStrings that exist as polygon, yet
UNION ( SELECT osm_id, tags, way FROM planet_polygon WHERE strpos(array_to_string(akeys(tags),','),'wikipedia')>0 )
) AS wikistaff
) AS wikiobjects
WHERE strpos(wikipedia,':')>0 -- remove tags with no language defined for example wikipedia=Artikel
ORDER BY article,lang ASC
)
;
ALTER TABLE wiwosm OWNER TO master;
GRANT ALL ON TABLE wiwosm TO master;
GRANT SELECT ON TABLE wiwosm TO public;
CREATE INDEX geom_index ON wiwosm USING GIST ( way ); -- geometry index
CREATE INDEX article_lang_index ON wiwosm (article, lang ASC); -- index on articles and languages
COMMIT;
EOQ;

		pg_query($this->conn,$query);
		if($e = pg_last_error()) {
			trigger_error($e, E_USER_ERROR);
			$this->exithandler();
		} else {
			echo 'wiwosm DB upgraded in '.((microtime(true)-$this->start)/60)." min\n";
		}
	}


	function createlinks($lang, $article, $geojson, &$lastlang = '') {
		// for every osm object with a valid wikipedia-tag print the geojson to file
		$filepath = $this->getFilePath($lang,$article);

		$handle = gzopen($filepath,'w');
		gzwrite($handle,$geojson);
		gzclose($handle);
		/*
		$handle = fopen($filepath,'w');
		fwrite($handle,$row['geojson']);
		fclose($handle);
		*/

		// just do a new connection if we get another lang than in loop before
		if ($lastlang!=$lang) {
			echo 'Try new lang:'.$lang."\n";
			$lastlang=$lang;
			mysql_close();
			$db = mysql_connect($lang. 'wiki-p.db.toolserver.org', $this->toolserver_mycnf['user'], $this->toolserver_mycnf['password']); 
			if (!$db || !mysql_select_db($lang. 'wiki_p')) {
				//echo 'Could not fetch interwikilinks for lang ' . $row['lang'] . ' in article: '. $row['article'] . "\n" . mysql_error() . "\n";
				$this->logUnknownLang($lang,$article);
				// return that we should skip this lang because there are errors
				return false;
			}
		}
		$mysql = 'SELECT `ll_lang`,`ll_title` FROM `'.$lang.'wiki_p`.`langlinks` WHERE `ll_from` =(SELECT `page_id` FROM `'.$lang."wiki_p`.`page` WHERE `page_namespace`=0 AND `page_is_redirect`=0 AND `page_title` = '".str_replace(array(' ','\''),array('_','\\\''),$article)."' LIMIT 1) LIMIT 300; ";

		$langres = mysql_query($mysql); 
		// for every interwikilink do a hard link to the real file written above
		while ($langrow = mysql_fetch_assoc($langres)) {
			$linkpath = $this->getFilePath($langrow['ll_lang'],$langrow['ll_title']);
			unlink($linkpath);
			link($filepath,$linkpath);
			unset($langrow,$linkpath);
		}
		// free the memory
		unset($filepath,$handle,$mysql,$langres);
		return true;
	}


	function updateOneObject($lang,$article) {
		$a = str_replace('"','\\\\"',pg_escape_string($article));
		$l = str_replace('"','\\\\"',pg_escape_string($lang));
		$sql = 'SELECT '.self::simplifyGeoJSON.' FROM (
			( SELECT way FROM planet_polygon WHERE (tags @> E\'"wikipedia:'.$l.'"=>"'.$a.'"\') OR (tags @> E\'"wikipedia"=>"'.$l.':'.$a.'"\') )
			UNION ( SELECT way FROM planet_line WHERE ( (tags @> E\'"wikipedia:'.$l.'"=>"'.$a.'"\') OR (tags @> E\'"wikipedia"=>"'.$l.':'.$a.'"\') ) AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) )
			UNION ( SELECT way FROM planet_point WHERE (tags @> E\'"wikipedia:'.$l.'"=>"'.$a.'"\') OR (tags @> E\'"wikipedia"=>"'.$l.':'.$a.'"\') )
			) AS wikistaff
			';

		$result = pg_query($this->conn,$sql);
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

		if ($result && pg_num_rows($result) == 1 ) {
			$row = pg_fetch_assoc($result);
			$this->createlinks($lang, $article, $row['geojson']);
		}
	}

	function processOsmItems() {
		$sql = '( SELECT lang,article,'.self::simplifyGeoJSON.' FROM  wiwosm GROUP BY lang,article ORDER BY lang )';

		// this consumes just too mutch memory:
		/*
		$result = pg_query($conn, $sql);
		if (!$result) {
		echo "Fail to fetch results from postgis \n";
		exit;
		}
		*/

		// so we have to use a cursor because its too much data:
		if (!pg_query($this->conn,'BEGIN WORK') || !pg_query($this->conn,'DECLARE osmcur NO SCROLL CURSOR FOR '.$sql)) {
			echo 'Could not declare cursor'. "\n" . pg_last_error() . "\n";
			$this->exithandler();
		}

		$lastlang = '';
		$skiplang = false;
		$count = 0;

		$result = pg_query($this->conn,'FETCH 500 FROM osmcur');

		$fetchcount = pg_num_rows($result);

		echo 'Get the first '.$fetchcount.' rows.'."\n";

		//damn cursor loop:
		while ($fetchcount > 0) {
			while ($row = pg_fetch_assoc($result)) {

				// if the lang is not a known valid language just try the next one
				//if(!in_array($row['lang'],$alllang)) continue;
				if ($skiplang && $lastlang==$row['lang']) continue;

				$skiplang = !$this->createlinks($row['lang'], stripcslashes(urldecode($row['article'])), $row['geojson'], $lastlang);
				// free the memory
				unset($row);
			}
			$count += $fetchcount;
			echo $count.' results processed'."\n";
			$result = pg_query($this->conn,'FETCH 500 FROM osmcur');
			$fetchcount = pg_num_rows($result);
		}

		pg_query($this->conn,'CLOSE osmcur');
		pg_query($this->conn,'COMMIT WORK');
	}

} 
?>