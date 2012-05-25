<?php
class Wiwosm {

	private $toolserver_mycnf;

	//$alllang = array('aa','ab','ace','af','ak','als','am','an','ang','ar','arc','arz','as','ast','av','ay','az','ba','bar','bat-smg','bcl','be','be-x-old','bg','bh','bi','bjn','bm','bn','bo','bpy','br','bs','bug','bxr','ca','cbk-zam','cdo','ce','ceb','ch','cho','chr','chy','ckb','co','cr','crh','cs','csb','cu','cv','cy','cz','da','de','diq','dk','dsb','dv','dz','ee','el','eml','en','eo','epo','es','et','eu','ext','fa','ff','fi','fiu-vro','fj','fo','fr','frp','frr','fur','fy','ga','gag','gan','gd','gl','glk','gn','got','gu','gv','ha','hak','haw','he','hi','hif','ho','hr','hsb','ht','hu','hy','hz','ia','id','ie','ig','ii','ik','ilo','io','is','it','iu','ja','jbo','jp','jv','ka','kaa','kab','kbd','kg','ki','kj','kk','kl','km','kn','ko','koi','kr','krc','ks','ksh','ku','kv','kw','ky','la','lad','lb','lbe','lg','li','lij','lmo','ln','lo','lt','ltg','lv','map-bms','mdf','mg','mh','mhr','mi','minnan','mk','ml','mn','mo','mr','mrj','ms','mt','mus','mwl','my','myv','mzn','na','nah','nan','nap','nb','nds','nds-nl','ne','new','ng','nl','nn','no','nov','nrm','nv','ny','oc','om','or','os','pa','pag','pam','pap','pcd','pdc','pfl','pi','pih','pl','pms','pnb','pnt','ps','pt','qu','rm','rmy','rn','ro','roa-rup','roa-tara','ru','rue','rw','sa','sah','sc','scn','sco','sd','se','sg','sh','si','simple','sk','sl','sm','sn','so','sq','sr','srn','ss','st','stq','su','sv','sw','szl','ta','te','tet','tg','th','ti','tk','tl','tn','to','tpi','tr','ts','tt','tum','tw','ty','udm','ug','uk','ur','uz','ve','vec','vi','vls','vo','wa','war','wo','wuu','xal','xh','xmf','yi','yo','za','zea','zh','zh-cfr','zh-classical','zh-min-nan','zh-yue','zu');

	const simplifyGeoJSON = 'ST_AsGeoJSON(
		CASE
			WHEN ST_NPoints(ST_Collect(way))<10000 THEN ST_Collect(way)
			WHEN ST_NPoints(ST_Collect(way)) BETWEEN 10000 AND 20000 THEN ST_SimplifyPreserveTopology(ST_Collect(way),(ST_Perimeter(ST_Collect(way))+ST_Length(ST_Collect(way)))/500000)
			WHEN ST_NPoints(ST_Collect(way)) BETWEEN 20000 AND 40000 THEN ST_SimplifyPreserveTopology(ST_Collect(way),(ST_Perimeter(ST_Collect(way))+ST_Length(ST_Collect(way)))/200000)
			WHEN ST_NPoints(ST_Collect(way)) BETWEEN 40000 AND 60000 THEN ST_SimplifyPreserveTopology(ST_Collect(way),(ST_Perimeter(ST_Collect(way))+ST_Length(ST_Collect(way)))/150000)
			ELSE ST_SimplifyPreserveTopology(ST_Collect(way),(ST_Perimeter(ST_Collect(way))+ST_Length(ST_Collect(way)))/100000)
		END
	,9) AS geojson';

	const JSON_PATH = '/mnt/user-store/wiwosm/geojsongz';
	public $json_path;

	private $conn;

	private $start;

	private $lastlang;

	function __construct() {
		$this->start = microtime(true);
		$this->json_path = self::JSON_PATH;
		$this->toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
		$this->openPgConnection();
	}

	function openPgConnection() {
		// open psql connection
		$this->conn = pg_connect('host=sql-mapnik dbname=osm_mapnik');
		// check for connection error
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
		//pg_set_client_encoding($this->conn, UNICODE);
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
		$article = str_replace('_',' ',$article);
		$hash = $this->fnvhash($lang.$article);
		$path = $this->json_path.'/'.substr($hash,0,2).'/'.substr($hash,0,4);
		if (!file_exists($path)) @mkdir($path, 0755, true);
		$path .= '/'.$hash.'_'.substr(str_replace(array("\0",'/'),array('','-'),$lang.'_'.$article),0,230).'.geojson.gz';
		unset($hash);
		return $path;
	}

	function logUnknownLang($l,$a) {
		error_log($l."\t".$a."\n",3,'/home/master/unknown.csv');
	}

	function testAndRename() {
		//$countFiles = system('ls -RU1 --color=never '.$json_path.' | wc -l');
		echo 'Execution time: '.((microtime(true)-$this->start)/60)."min\n";
		echo 'Counting generated files …'."\n";
		$countFiles = system('find '.$this->json_path.' -type f | wc -l');
		// if there are more than 100000
		if ( $countFiles > 100000 ) {
			exec('rm -r ' . self::JSON_PATH . '_old');
			rename(self::JSON_PATH , self::JSON_PATH . '_old');
			rename($this->json_path , self::JSON_PATH );
		}

	}

	function exithandler() {
		echo 'Execution time: '.((microtime(true)-$this->start)/60)."min\n";
		echo 'Peak memory usage: '.(memory_get_peak_usage(true)/1024/1024)."MB\n";
		pg_close($this->conn);
		exit;
	}

	function createIndices() {
$query = <<<EOQ
CREATE INDEX geom_index ON wiwosm USING GIST ( way ); -- geometry index
CREATE INDEX article_lang_index ON wiwosm (article, lang ASC); -- index on articles and languages
EOQ;
		pg_query($this->conn,$query);
	}

	function updateWiwosmDB() {
$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm;
CREATE TABLE wiwosm AS (
SELECT osm_id, way, lower(split_part(wikipedia, ':', 1)) AS lang, split_part(substring(wikipedia from position(':' in wikipedia)+1),'#', 1) AS article, split_part(wikipedia,'#', 2) AS anchor FROM (
SELECT osm_id, way,
regexp_replace(
  substring(
    concat(
      substring(array_to_string(akeys(tags),',') from 'wikipedia:?[^,]*'), -- this is the tagname for example "wikipedia" or "wikipedia:de"
      ':',
      regexp_replace(
        tags->substring(array_to_string(akeys(tags),',') from '[^,]*wikipedia:?[^,]*'), -- get the first wikipedia tag from hstore
        '^https?://(\\w*)\\.wikipedia\\.org/wiki/(.*)$', -- matches if the value is a wikipedia url (otherwise it is an article)
        '\\1:\\2' -- get the domain prefix and use it as language key followed by the article name
      )
    ) -- resulting string is for example wikipedia:de:Dresden
    from 11 -- remove the "wikipedia:" prefix
  ),
  '^(\\w*:)\\1','\\1' -- it is possible that there is such a thing like "de:de:Artikel" left if there was a tag like "wikipedia:de=http://de.wikipedia.org/wiki/Artikel", so remove double language labels
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
ALTER TABLE wiwosm ADD COLUMN lang_ref integer;
ALTER TABLE wiwosm OWNER TO master;
GRANT ALL ON TABLE wiwosm TO master;
GRANT SELECT ON TABLE wiwosm TO public;
COMMIT;
EOQ;

		pg_query($this->conn,$query);
		if($e = pg_last_error()) {
			trigger_error($e, E_USER_ERROR);
			$this->exithandler();
		} else {
			echo 'wiwosm DB basic table build in '.((microtime(true)-$this->start)/60)." min\nStarting additional relation adding …\n";
			$this->addMissingRelationObjects();
			$this->createIndices();
			$this->linkarticlelanguages();
			echo 'wiwosm DB upgraded in '.((microtime(true)-$this->start)/60)." min\n";
		}
	}

	function getAllMembers($memberscsv,&$nodelist,&$waylist,&$rellist) {
		$subrellist = array();
		$members = str_getcsv($memberscsv,',','"');
		for($i=0; $i<count($members); $i+=2) {
			$id = substr($members[$i],1);
			switch ($members[$i][0]) {
				case 'n':
					$nodelist[] = $id;
					break;
				case 'w':
					$waylist[] = $id;
					break;
				case 'r':
					$subrellist[] = $id;
					break;
			}
		}

		$newrels = array_diff($subrellist,$rellist);
		// if there are relations we havn't seen before
		if (count($newrels)>0) {
			$newrelscomplement = array();
			foreach ($newrels AS $rel) {
				$newrelscomplement[] = '-'.$rel;
				$rellist[] = $rel;
			}

			$newrelscsv = implode(',',$newrelscomplement);

			$result = pg_execute($this->conn,'get_existing_member_relations',array('{'.$newrelscsv.'}'));
			$existingrels = ($result) ? pg_fetch_all_columns($result, 0) : array();
			// we can simply add the existing relations with there negative id as if they were nodes or ways
			$nodelist = array_merge($nodelist,$existingrels);
			$waylist = array_merge($waylist,$existingrels);

			// all other relations we have to pick from the planet_rels table
			$othersubrels = array_diff($newrelscomplement,$existingrels);
			if (count($othersubrels)>0) {
				$othersubrelscsv = '';
				// first strip of the "-" and build csv
				foreach($othersubrels AS $subrel) {
					$othersubrelscsv .= ','.substr($subrel,1);
				}
				$othersubrelscsv = substr($othersubrelscsv,1);

				$res = pg_execute($this->conn,'get_member_relations_planet_rels',array('{'.$othersubrelscsv.'}'));
				if ($res) {
					// fetch all members of all subrelations and combine them to one csv string
					$allsubmembers = pg_fetch_all_columns($res, 0);
					$allsubmemberscsv = '';
					foreach($allsubmembers AS $submem) {
						// if submembers exist add them to csv
						if ($submem) $allsubmemberscsv .= ','.substr($submem,1,-1);
					}
					// call this function again to process all subrelations and add them to the arrays
					$this->getAllMembers(substr($allsubmemberscsv,1),$nodelist,$waylist,$rellist);
				}
			}
		}
	}

	function addMissingRelationObjects() {
		// prepare some often used queries:

		// search for existing relations that are build in osm2pgsql default scheme ( executed in getAllMembers function!)
		$result = pg_prepare($this->conn,'get_existing_member_relations','SELECT DISTINCT osm_id FROM (
			(SELECT osm_id FROM planet_point WHERE osm_id = ANY ($1))
			UNION (SELECT osm_id FROM planet_line WHERE osm_id = ANY ($1))
			UNION (SELECT osm_id FROM planet_polygon WHERE osm_id = ANY ($1))
		) AS existing');
		if ($result === false) $this->exithandler();

		// fetch all members of all subrelations and combine them to one csv string ( executed in getAllMembers function!)
		$result = pg_prepare($this->conn,'get_member_relations_planet_rels','SELECT members FROM planet_rels WHERE id = ANY ($1)');
		if ($result === false) $this->exithandler();

		// insert ways and polygons in wiwosm
		$result = pg_prepare($this->conn,'insert_relways_wiwosm','INSERT INTO wiwosm SELECT $1 AS osm_id, ST_Collect(way) AS way , $2 AS lang, $3 AS article, $4 AS anchor FROM (
			(SELECT way FROM planet_polygon WHERE osm_id = ANY ($5) )
			UNION ( SELECT way FROM planet_line WHERE osm_id = ANY ($5) AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) )
			) AS members');
		if ($result === false) $this->exithandler();

		// insert nodes in wiwosm
		$result = pg_prepare($this->conn,'insert_relnodes_wiwosm','INSERT INTO wiwosm SELECT $1 AS osm_id, ST_Collect(way) AS way , $2 AS lang, $3 AS article, $4 AS anchor FROM (
			(SELECT way FROM planet_point WHERE osm_id = ANY ($5) )
			) AS members');
		if ($result === false) $this->exithandler();

		$query = "SELECT id,members,tags FROM planet_rels WHERE strpos(array_to_string(tags,','),'wikipedia')>0 AND -id NOT IN ( SELECT osm_id FROM wiwosm WHERE osm_id<0 )";
		$result = pg_query($this->conn,$query);
		while ($row = pg_fetch_assoc($result)) {
			// if the relation has no members ignore it and try the next one
			if (!$row['members']) continue;
			$lang = '';
			$article = '';
			$anchor = '';
			$tagscsv = str_getcsv(substr($row['tags'],1,-1),',','"');
			for($i=0; $i<count($tagscsv); $i+=2) {
				$key = $tagscsv[$i];
				if (substr($key,0,9) == 'wikipedia') {
					$wiki = preg_replace('/^([^:]*:){2}/','${1}',substr($key.':'.preg_replace('#^https?://(\w*)\.wikipedia\.org/wiki/(.*)$#','${1}:${2}',urldecode($tagscsv[$i+1])),10));
					$pos = strpos($wiki,':');
					if ($pos !== false) {
						$lang = strtolower(substr($wiki,0,$pos));
						$rest = substr($wiki,$pos+1);
						$posanchor = strpos($rest,'#');
						if ($posanchor === false) {
							$article = $rest;
						} else {
							$article = substr($rest,0,$posanchor);
							$anchor = substr($rest,$posanchor+1);
						}

						$nodelist = array();
						$waylist = array();
						$rellist = array($row['id']);
						$this->getAllMembers(substr($row['members'],1,-1),$nodelist,$waylist,$rellist);
						$nodelist = array_unique($nodelist);
						$waylist = array_unique($waylist);
						$nodescsv = implode(',',$nodelist);
						$wayscsv = implode(',',$waylist);
						$hasNodes = (count($nodelist)>0);
						$hasWays = (count($waylist)>0);
						if ($hasWays) {
							pg_execute($this->conn,'insert_relways_wiwosm',array('-'.$row['id'],$lang,$article,$anchor,'{'.$wayscsv.'}'));
						}
						if ($hasNodes) {
							pg_execute($this->conn,'insert_relnodes_wiwosm',array('-'.$row['id'],$lang,$article,$anchor,'{'.$nodescsv.'}'));
						}

						// found a wikipedia tag so stop looping the tags
						break;
					}

				}
			} 
		}
	}

	public static function hstoreToArray($hstore) {
		$ret_array = array();
		echo $hstore."\n";
		if (preg_match_all('/(?:^| )"((?:[^"]|(?<=\\\\)")*)"=>"((?:[^"]|(?<=\\\\)")*)"(?:$|,)/',$hstore,$matches)) {
			$count = count($matches[1]);
			if ($count == count($matches[2])) {
				for($i=0; $i<$count; $i++) {
					$lang = stripslashes($matches[1][$i]);
					$article = stripslashes($matches[2][$i]);
					$ret_array[$lang] = $article;
				}
			}
		}
		return $ret_array;
	}

	public static function fixUTF8($str) {
		$curenc = mb_detect_encoding($str);
		if ($curenc != 'UTF-8') {
			if ($curenc === false) {
				// if mb_detect_encoding failed we have to enforce clean UTF8 somehow
				return mb_convert_encoding(utf8_encode($str), 'UTF-8', 'UTF-8');
			}
			// if we can guess the encoding we can convert it
			return mb_convert_encoding($str,'UTF-8',$curenc);
		} elseif (!mb_check_encoding($str,'UTF-8')) {
			// if there are invalid bytes try to remove them
			return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
		}
		// if it is already clean UTF-8 there should be no problems
		return $str;
	}

	function queryInterWikiLanguages($lang, $article) {
		// if no lang or article is given, we can stop here
		if (!$lang || !$article) return false;
		// just do a new connection if we get another lang than in loop before
		if ($this->lastlang!=$lang) {
			echo 'Try new lang:'.$lang."\n";
			$this->lastlang=$lang;
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

		$langarray = array();
		$langres = mysql_query($mysql);
		if ($langres !== false) {
			while ($langrow = mysql_fetch_assoc($langres)) {
				$langarray[$langrow['ll_lang']] = str_replace('_',' ',$langrow['ll_title']);
			}
		}
		return $langarray;
	}

	function escape($str) {
		return str_replace(array('\\','"'),array('\\\\','\\"'),$str);
	}

	function linkarticlelanguages() {
		$query = "SELECT lang,article FROM wiwosm ORDER BY lang,article";

		// lets update every row and use a cursor for that
		if (!pg_query($this->conn,'BEGIN WORK') || !pg_query($this->conn,'DECLARE updatelangcur NO SCROLL CURSOR FOR '.$query.' FOR UPDATE OF wiwosm')) {
			echo 'Could not declare cursor for updating language refs'. "\n" . pg_last_error() . "\n";
			$this->exithandler();
		}

		$langbefore = '';
		$articlebefore = '';

		// prepare some sql queries that are used very often:

		// this is to search for an entry in wiwosm_wiki_ll table by given article and language
		$result = pg_prepare($this->conn,'get_lang_id','SELECT lang_id FROM wiwosm_wiki_ll WHERE languages @> $1::hstore');
		if ($result === false) $this->exithandler();

		// insert a new line in wiwosm_wiki_ll. We have to give the datatype for the text[][] explicit here so we can't use pg_prepare
		$result = pg_query($this->conn,'PREPARE insert_wiwosm_wiki_ll (text,text,text[][]) AS INSERT INTO wiwosm_wiki_ll (lang_id,lang_origin,article_origin,languages) VALUES (DEFAULT,$1,$2,hstore($3)) RETURNING lang_id');
		if ($result === false) $this->exithandler();

		// update the lang_ref column in wiwosm table by using the current row from updatelangcur cursor
		$result = pg_prepare($this->conn,'update_wiwosm_lang_ref','UPDATE wiwosm SET lang_ref=$1 WHERE CURRENT OF updatelangcur');
		if ($result === false) $this->exithandler();

		$result = pg_prepare($this->conn,'fetch_next_updatelangcur','FETCH NEXT FROM updatelangcur');
		if ($result === false) $this->exithandler();
		$result = pg_execute($this->conn,'fetch_next_updatelangcur',array());
		$fetchcount = pg_num_rows($result);

		while ($fetchcount == 1) {
			$row = pg_fetch_assoc($result);
			$article = str_replace('_',' ',stripcslashes(self::fixUTF8(urldecode($row['article']))));
			$lang = $row['lang'];
			if ($langbefore !== $lang || $articlebefore !== $article) {
				$langbefore = $lang;
				$articlebefore = $article;
				$lang_id = '-1';
				$params = array('"'.$this->escape($lang).'"=>"'.$this->escape($article).'"');
				$result = pg_execute($this->conn,'get_lang_id',$params);
				if ($result && pg_num_rows($result) == 1) {
					// if we found an entry in our wiwosm_wiki_ll table we use that id to link
					$lang_id = pg_fetch_result($result,0,0);
				} else {
					// if there was no such entry we have to query the interwikilinks mysql db
					$langarray = $this->queryInterWikiLanguages($lang,$article);
					if ($langarray !== false) {
						$langarray[$lang] = $article;
						$hstorestring = '{';
						foreach ($langarray as $l => $a) {
							$hstorestring .= '{"'.$this->escape($l).'","'.$this->escape($a).'"},';
						}
						$hstorestring = rtrim($hstorestring,',').'}';
						$idres = pg_execute($this->conn,'insert_wiwosm_wiki_ll',array($lang,$article,$hstorestring));
						if ($idres && pg_num_rows($idres) == 1 ) {
							$lang_id = pg_fetch_result($idres,0,0);
						}
					}
				}
			}
			pg_execute($this->conn,'update_wiwosm_lang_ref',array($lang_id));
			if ($result === false) $this->exithandler();
			$result = pg_execute($this->conn,'fetch_next_updatelangcur',array());
			$fetchcount = pg_num_rows($result);
		}
		pg_query($this->conn,'CLOSE updatelangcur');
		pg_query($this->conn,'COMMIT WORK');
	}

	function createLangTable() {
		$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm_wiki_ll;
CREATE TABLE wiwosm_wiki_ll (
	lang_id serial PRIMARY KEY,
	lang_origin text,
	article_origin text,
	languages hstore
)
;
ALTER TABLE wiwosm_wiki_ll OWNER TO master;
GRANT ALL ON TABLE wiwosm_wiki_ll TO master;
GRANT SELECT ON TABLE wiwosm_wiki_ll TO public;
CREATE INDEX languages_idx ON wiwosm_wiki_ll USING GIST (languages);
COMMIT;
EOQ;
		pg_query($this->conn,$query);
	}


	function createlinks($lang, $article, $geojson, $langarray = array(), $neednoIWLLupdate = false) {
		// for every osm object with a valid wikipedia-tag print the geojson to file
		$filepath = $this->getFilePath($lang,$article);

		// we need no update of the Interwiki language links if it is given by parameter and the file exists already
		$neednoIWLLupdate &= file_exists($filepath);

		$handle = gzopen($filepath,'w');
		gzwrite($handle,$geojson);
		gzclose($handle);

		// check if we need an update of the Interwiki language links
		if ($neednoIWLLupdate) return true;

		//$langarray = $this->queryInterWikiLanguages($lang,$article);
		// for every interwikilink do a hard link to the real file written above
		foreach ($langarray as $l => $a) {
			if ($l != $lang) {
				$linkpath = $this->getFilePath($l,$a);
				@unlink($linkpath);
				link($filepath,$linkpath);
				unset($langrow,$linkpath);
			}
		}
		// free the memory
		unset($filepath,$handle,$mysql,$langres);
		return true;
	}


	function updateOneObject($lang,$article) {
		$articlefilter = '( tags @> $1::hstore ) OR ( tags @> $2::hstore ) OR ( tags @> $3::hstore ) OR ( tags @> $4::hstore ) OR ( tags @> $5::hstore ) OR ( tags @> $6::hstore )';
		$sql = 'SELECT '.self::simplifyGeoJSON.' FROM (
			( SELECT way FROM planet_polygon WHERE '.$articlefilter.' )
			UNION ( SELECT way FROM planet_line WHERE ( '.$articlefilter.' ) AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) )
			UNION ( SELECT way FROM planet_point WHERE '.$articlefilter.' )
			) AS wikistaff
			';
		pg_prepare($this->conn,'select_wikipedia_object',$sql);

		$a = $this->escape($article);
		$aurl = urlencode(str_replace(' ','_',$a));
		$l = $this->escape($lang);
		$params = array('"wikipedia:'.$l.'"=>"'.$a.'"',
				'"wikipedia"=>"'.$l.':'.$a.'"',
				'"wikipedia"=>"http://'.$l.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia"=>"https://'.$l.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia:'.$l.'"=>"http://'.$l.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia:'.$l.'"=>"https://'.$l.'.wikipedia.org/wiki/'.$aurl.'"');

		$result = pg_execute($this->conn,'select_wikipedia_object',$params);
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

		if ($result && pg_num_rows($result) == 1 ) {
			$row = pg_fetch_assoc($result);
			$this->createlinks($lang, $article, $row['geojson']);
		}
	}

	function processOsmItems() {
		// to avoid problems with geometrycollections first dump all geometries and collect them again
		$sql = 'SELECT lang_origin, article_origin, languages, geojson FROM ( SELECT lang_ref,'.self::simplifyGeoJSON.' FROM  (SELECT lang_ref,(ST_Dump(way)).geom AS way FROM wiwosm WHERE lang_ref != -1 ) AS geomdump GROUP BY lang_ref ) AS wiwosm_refs, wiwosm_wiki_ll WHERE wiwosm_refs.lang_ref = wiwosm_wiki_ll.lang_id';

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


		$count = 0;

		// fetch from osmcur in steps of 1000 elements
		$result = pg_prepare($this->conn,'fetch_osmcur','FETCH 1000 FROM osmcur');
		if ($result === false) $this->exithandler();

		$result = pg_execute($this->conn,'fetch_osmcur',array());

		$fetchcount = pg_num_rows($result);

		echo 'Get the first '.$fetchcount.' rows.'."\n";

		//damn cursor loop:
		while ($fetchcount > 0) {
			while ($row = pg_fetch_assoc($result)) {

				$this->createlinks($row['lang_origin'], $row['article_origin'], $row['geojson'], self::hstoreToArray($row['languages']),true);
				// free the memory
				unset($row);
			}
			$count += $fetchcount;
			echo $count.' results processed'."\n";
			$result = pg_execute($this->conn,'fetch_osmcur',array());
			$fetchcount = pg_num_rows($result);
		}

		pg_query($this->conn,'CLOSE osmcur');
		pg_query($this->conn,'COMMIT WORK');
	}

} 
?>