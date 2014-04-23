<?php

/**
 * Wiwosm main class
 * @author Christoph Wagner
 * @version 1.6
 */

class Wiwosm {

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

	const PROJECT_PATH = '/data/project/wiwosm/';
	public $json_path;

	private $pgconn;
	private $mysqliconn;

	private $prep_wikidata_by_lang_article;
	private $prep_wikidata_by_wikidata_ref;

	private $lastlang;
	private $lastarticle;

	private $start;

	/**
	 * This is what to do on creating a Wiwosm object
	 * (start timer, parse ini file for db connection … )
	 **/
	function __construct($loglevel = 0) {
		$this->start = microtime(true);
		$this->pgconn = false;
		$this->mysqliconn = false;
		$this->loglevel = $loglevel;
		$this->json_path = self::PROJECT_PATH . 'output/geojsongz';
	}

	/**
	 * This is what to do before exiting this program
	 * (output time and memory consumption, close all db connections … )
	 **/
	function __destruct() {
		$this->logMessage('Execution time: '.((microtime(true)-$this->start)/60)."min\n", 2);
		$this->logMessage('Peak memory usage: '.(memory_get_peak_usage(true)/1024/1024)."MB\n", 2);
		if ($this->pgconn) {
			pg_close($this->pgconn);
		}
		if ($this->mysqliconn) {
			$this->mysqliconn->close();
		}
	}

	/**
	 * Log a message at a specified loglevel
	 **/
	function logMessage($message, $level) {
		if ($this->loglevel >= $level) {
			echo $message;
		}
	}

	/**
	 * Open a postgresql db connection
	 **/
	function getPgConn() {
		if (!$this->pgconn) {
			// open psql connection
			$this->pgconn = pg_connect('user=osm host=labsdb1004.eqiad.wmnet dbname=gis');
			// check for connection error
			if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
			//pg_set_client_encoding($this->pgconn, UNICODE);
		}
		return $this->pgconn;
	}

	/**
	 * Open a mysql db connection to wikidata
	 **/
	function getMysqlConn() {
		if (!$this->mysqliconn) {
			$mycnf = parse_ini_file(self::PROJECT_PATH . "replica.my.cnf");
			$this->mysqliconn = new mysqli('wikidatawiki.labsdb', $mycnf['user'], $mycnf['password'], 'wikidatawiki_p');
			if (mysqli_connect_errno()) {
				$this->logMessage('Mysql connection failed: '.mysqli_connect_error()."\n", 1);
				exit();
			}
		}
		return $this->mysqliconn;
	}

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
	function getFilePath($lang, $article, $create_if_missing = true, $relativ = false) {
		//$hash = md5($lang.str_replace('_',' ',$article));
		// use fnvhash because its much faster than md5
		$article = str_replace('_',' ',$article);
		$hash = $this->fnvhash($lang.$article);
		$relpath = substr($hash,0,2).'/'.substr($hash,0,4);
		$fullpath = $this->json_path.'/'.$relpath;
		$path = ($relativ) ? $relpath : $fullpath;
		if ($create_if_missing && !file_exists($fullpath)) @mkdir($fullpath, 0755, true);
		$path .= '/'.$hash.'_'.substr(str_replace(array("\0",'/'),array('','-'),$lang.'_'.$article),0,230).'.geojson.gz';
		unset($hash);
		return $path;
	}

	/**
	 * If we find a trash article while connecting to $lang.'wiki-p.db.toolserver.org' we can log it here to watch the problem later
	 * @param string $lang the language of the given article
	 * @param string $article the name of the article
	 **/
	function logUnknownLang($l,$a) {
		error_log($l."\t".$a."\n",3, self::PROJECT_PATH . 'unknown.csv');
	}

	/**
	 * Write all broken languages with their articles into a nice html logfile
	 **/
	function logUnknown() {
		$htmlrows = '';
		// get all broken languages
		$query = 'SELECT osm_id,lang,article,array_agg(ST_GeometryType(way)) AS geomtype FROM wiwosm WHERE wikidata_ref = -1 GROUP BY osm_id,lang,article';
		$result = pg_query($this->getPgConn(),$query);
		$count = pg_num_rows($result);
		while ($row = pg_fetch_assoc($result)) {
			$osm_id = $row['osm_id'];
			$type = 'way';
			if ($row['geomtype']=='{ST_Point}') $type = 'node';
			if ($row['osm_id'] < 0) {
				$type = 'relation';
				// if relation remove leading minus
				$osm_id = substr($row['osm_id'],1);
			}

			$htmlrows .= '      <tr>'."\n";
			$htmlrows .= '        <td><a href="http://www.openstreetmap.org/browse/'.$type.'/'.$osm_id.'">'.$osm_id.' ('.$type.')</a></td>'."\n";
			$htmlrows .= '        <td><a href="http://www.openstreetmap.org/edit?editor=potlatch2&'.$type.'='.$osm_id.'">Potlatch2</a>, <a href="http://127.0.0.1:8111/load_object?objects='.$type[0].$osm_id.'">JOSM/Merkaator</a></td>'."\n";
			$htmlrows .= '        <td>'.htmlspecialchars($row['lang']).'</td>'."\n";
			$htmlrows .= '        <td>'.htmlspecialchars($row['article']).'</td>'."\n";
			$htmlrows .= '      </tr>'."\n";
		}
		$now = date(DATE_RFC822);

		$sortscript = '';
		$sortmessage = '';
		if ($count < 1000) {
			$sortscript = '<script src="sorttable.js"></script>';
			$sortmessage = '<p><b>Hint:</b> Columns are now sortable by clicking if you have JS enabled! (thanks to <a href="http://wiki.openstreetmap.org/wiki/User:Jjaf.de">User Jjaf.de</a> for the idea)</p>';
		}

$html = <<<EOT
<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <title>WIWOSM broken languages</title>
    $sortscript
  </head>
  <body>
    <h1>$count unknown wikipedia tags found while WIWOSM-processing</h1>
    <h2>$now</h2>
    $sortmessage
    <p>12.01.2013: Now all wikipedia tags with undefined language like wikipedia=article are shown, too! Sorry for the big load!</p>
    <p>14.01.2013: Edit links are now available for potlatch2 and JOSM or Merkaator with Remotecontrol plugin.</p>
    <table border=1 class="sortable">
      <tr align="left">
        <th width="20%">OSM-Object</th>
        <th>Edit links</th>
        <th>language</th>
        <th>article</th>
      </tr>
$htmlrows
    </table>
  </body>
</html>
EOT;

		//write that stuff to a file
		$fh = fopen(self::PROJECT_PATH . 'public_html/wiwosmlog/broken.html','w');
		fwrite($fh, $html);
		fclose($fh);
	}

	/**
	 * Write all broken languages with their articles into a nice JSON logfile
	 **/
	function logUnknownJSON() {
		// get all broken languages
		$query = 'SELECT DISTINCT ON (osm_id) osm_id,lang,article,geomtype,iso2,name FROM ( SELECT osm_id,lang,article,array_agg(ST_GeometryType(way)) AS geomtype, ST_Transform(ST_SetSRID(ST_Extent(way),900913),4326) AS extent FROM wiwosm WHERE wikidata_ref = -1 GROUP BY osm_id,lang,article ) AS w LEFT JOIN wiwosm_tm_world_borders_simple ON ST_Intersects(extent, ST_SetSRID(geom,4326))';
		$result = pg_query($this->getPgConn(),$query);
		$count = pg_num_rows($result);
		$json = '{"created":"'.date(DATE_RFC822).'","count":"'.$count.'","items":[';
		$r = array();
		while ($row = pg_fetch_assoc($result)) {
			$r['i'] = $row['osm_id'];
			$r['t'] = 'w';
			if ($row['geomtype']=='{ST_Point}') $r['t'] = 'n';
			if ($row['osm_id'] < 0) {
				$r['t'] = 'r';
				// if relation remove leading minus
				$r['i'] = substr($row['osm_id'],1);
			}
			$r['l'] = $row['lang'];
			$r['a'] = $row['article'];
			$r['c'] = ''.$row['name'];
			$r['s'] = ''.$row['iso2'];
			$json .= json_encode($r).',';

		}
		$json = rtrim($json,',');
		$json .= ']}';

		//write that stuff to a gzipped json file
		$handle = gzopen(self::PROJECT_PATH . 'public_html/wiwosmlog/broken.json.gz','w');
		gzwrite($handle,$json);
		gzclose($handle);
	}

	/**
	 * We have to make sure that the full update process did work and so we simply test, if there are enough files in our update directory.
	 * If this is the case we remove the _old dir, move the current dir to _old and the _update to current dir.
	 **/
	function testAndRename() {
		//$countFiles = system('ls -RU1 --color=never '.$json_path.' | wc -l');
		$this->logMessage('Execution time: '.((microtime(true)-$this->start)/60)."min\n", 2);
		$this->logMessage('Counting generated files …'."\n", 2);
		$countFiles = system('find '.$this->json_path.' -type f | wc -l');
		// if there are more than 100000
		if ( $countFiles > 100000 ) {
			//exec('mv -T ' . self::JSON_PATH . '_old ' . self::JSON_PATH . '_old_remove');
			//exec('mv -T ' . self::JSON_PATH . ' ' . self::JSON_PATH . '_old');
			//exec('mv -T ' . $this->json_path . ' ' . self::JSON_PATH );
			//unlink(self::JSON_PATH);
			//symlink($this->json_path,'geojsongz');
			exec('ln -snf '.basename($this->json_path).' '.dirname($this->json_path).'/geojsongz');
			//rename(self::JSON_PATH . '_old',self::JSON_PATH . '_old_remove');
			//rename(self::JSON_PATH , self::JSON_PATH . '_old');
			//rename($this->json_path , self::JSON_PATH );
			// let cronie remove the old directory
			//exec('rm -rf /mnt/user-store/wiwosm/geojsongz_old_remove &');
		}

	}

	/**
	 * Just create some indices on wiwosm table that may help to speed things up.
	 **/
	function createIndices() {
$query = <<<EOQ
CREATE INDEX geom_index ON wiwosm USING GIST ( way ); -- geometry index
CREATE INDEX article_lang_index ON wiwosm USING btree (article, lang ASC); -- index on articles and languages
EOQ;
		pg_query($this->getPgConn(),$query);
	}

	/**
	 * Throw away the wiwosm table and rebuild it from the mapnik db
	 **/
	function updateWiwosmDB() {
$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm;
CREATE TABLE wiwosm AS (
SELECT osm_id, wikidata_ref, way, ( CASE WHEN strpos(wikipedia,':')>0 THEN lower(split_part(wikipedia, ':', 1)) ELSE '' END ) AS lang, split_part(substring(wikipedia from position(':' in wikipedia)+1),'#', 1) AS article, split_part(wikipedia,'#', 2) AS anchor FROM (
SELECT osm_id, way,
( CASE WHEN strpos(keys_string, 'wikipedia')>0 THEN
regexp_replace(
  substring(
    concat(
      substring(keys_string from 'wikipedia:?[^,]*'), -- this is the tagname for example "wikipedia" or "wikipedia:de"
      ':',
      regexp_replace(
        tags->substring(keys_string from 'wikipedia:?[^,]*'), -- get the first wikipedia tag from hstore
        '^(?:https?://)?(\\w*)\\.wikipedia\\.org/wiki/(.*)$', -- matches if the value is a wikipedia url (otherwise it is an article)
        '\\1:\\2' -- get the domain prefix and use it as language key followed by the article name
      )
    ) -- resulting string is for example wikipedia:de:Dresden
    from 11 -- remove the "wikipedia:" prefix
  ),
  '^(\\w*:)\\1','\\1' -- it is possible that there is such a thing like "de:de:Artikel" left if there was a tag like "wikipedia:de=http://de.wikipedia.org/wiki/Artikel", so remove double language labels
) ELSE '' END) AS "wikipedia",
( CASE WHEN wikidata ~ '^Q\\d+$' THEN -- try to get the wikidata ref from osm if it is formed like wikidata=Q1234
  CAST(substring(wikidata from 2) AS INTEGER) -- we strip the Q and cast to Integer
ELSE 0 END) AS "wikidata_ref"
FROM (
( SELECT osm_id, tags, array_to_string(akeys(tags),',') AS keys_string, tags->'wikidata' AS wikidata, way FROM planet_osm_point WHERE concat(',',array_to_string(akeys(tags),',')) ~ ',wiki(data|pedia)' )
UNION ( SELECT osm_id, tags, array_to_string(akeys(tags),',') AS keys_string, tags->'wikidata' AS wikidata, way FROM planet_osm_line WHERE concat(',',array_to_string(akeys(tags),',')) ~ ',wiki(data|pedia)' AND NOT EXISTS (SELECT 1 FROM planet_osm_polygon WHERE planet_osm_polygon.osm_id = planet_osm_line.osm_id) ) -- we don't want LineStrings that exist as polygon, yet
UNION ( SELECT osm_id, tags, array_to_string(akeys(tags),',') AS keys_string, tags->'wikidata' AS wikidata, way FROM planet_osm_polygon WHERE concat(',',array_to_string(akeys(tags),',')) ~ ',wiki(data|pedia)' )
) AS wikistaff
) AS wikiobjects
ORDER BY article,lang ASC
)
;
UPDATE wiwosm SET wikidata_ref=-1 WHERE lang = ANY (ARRAY['','http','subject','name','operator','related','sculptor','architect','maker']); -- we know that there could not be a language reference in Wikipedia for some lang values.
COMMIT;
EOQ;

		pg_query($this->getPgConn(),$query);
		if($e = pg_last_error()) {
			trigger_error($e, E_USER_ERROR);
			exit();
		} else {
			$this->logMessage('wiwosm DB basic table build in '.((microtime(true)-$this->start)/60)." min\nStarting additional relation adding …\n", 2);
			$this->addMissingRelationObjects();
			$this->logMessage('Missing Relations added '.((microtime(true)-$this->start)/60)." min\nCreate Indices and link articleslanguages …\n", 2);
			$this->createIndices();
			$this->map_wikidata_languages();
			$this->logMessage('wiwosm DB upgraded in '.((microtime(true)-$this->start)/60)." min\n", 2);
		}
	}

	/**
	 * Get all members of a relation and if there are children that are relations too recursivly traverse them
	 * @param string $memberscsv This is a comma separated string of relation children to process
	 * @param array $nodelist This is the list of all nodes we have traversed until now. It is passed by reference so we can add the current node members there.
	 * @param array $waylist Same as with nodes but for ways.
	 * @param array $rellist Same as with nodes but for relations. This list is also used to check for loopings while recursivly traverse the relations.
	 **/
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

			$result = pg_execute($this->getPgConn(),'get_existing_member_relations',array('{'.$newrelscsv.'}'));
			$existingrels = ($result) ? pg_fetch_all_columns($result, 0) : array();
			// we can simply add the existing relations with there negative id as if they were nodes or ways
			$nodelist = array_merge($nodelist,$existingrels);
			$waylist = array_merge($waylist,$existingrels);

			// all other relations we have to pick from the planet_osm_rels table
			$othersubrels = array_diff($newrelscomplement,$existingrels);
			if (count($othersubrels)>0) {
				$othersubrelscsv = '';
				// first strip of the "-" and build csv
				foreach($othersubrels AS $subrel) {
					$othersubrelscsv .= ','.substr($subrel,1);
				}
				$othersubrelscsv = substr($othersubrelscsv,1);

				$res = pg_execute($this->getPgConn(),'get_member_relations_planet_osm_rels',array('{'.$othersubrelscsv.'}'));
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

	/**
	 * Osm2pgsql doesn't get all relations by default in the standard mapnik database, because mapnik doesn't need them.
	 * But we need them, because they can be tagged with wikipedia tags so we have to look in the planet_osm_rels table, that is a preprocessing table for osm2pgsql that holds all the information we need, but in an ugly format.
	 * So we have to search in the tags and members arrays if we can find something usefull, get the objects from the mapnik tables and store it in wiwosm.
	 **/
	function addMissingRelationObjects() {
		// prepare some often used queries:
		$pgconn = $this->getPgConn();

		// search for existing relations that are build in osm2pgsql default scheme ( executed in getAllMembers function!)
		$result = pg_prepare($pgconn,'get_existing_member_relations','SELECT DISTINCT osm_id FROM (
			(SELECT osm_id FROM planet_osm_point WHERE osm_id = ANY ($1))
			UNION (SELECT osm_id FROM planet_osm_line WHERE osm_id = ANY ($1))
			UNION (SELECT osm_id FROM planet_osm_polygon WHERE osm_id = ANY ($1))
		) AS existing');
		if ($result === false) exit();

		// fetch all members of all subrelations and combine them to one csv string ( executed in getAllMembers function!)
		$result = pg_prepare($pgconn,'get_member_relations_planet_osm_rels','SELECT members FROM planet_osm_rels WHERE id = ANY ($1)');
		if ($result === false) exit();

		// insert ways and polygons in wiwosm
		$result = pg_prepare($pgconn,'insert_relways_wiwosm','INSERT INTO wiwosm SELECT $1 AS osm_id, $2 AS wikidata_ref, ST_Collect(way) AS way, $3 AS lang, $4 AS article, $5 AS anchor FROM (
			(SELECT way FROM planet_osm_polygon WHERE osm_id = ANY ($6) )
			UNION ( SELECT way FROM planet_osm_line WHERE osm_id = ANY ($6) AND NOT EXISTS (SELECT 1 FROM planet_osm_polygon WHERE planet_osm_polygon.osm_id = planet_osm_line.osm_id) )
			) AS members');
		if ($result === false) exit();

		// insert nodes in wiwosm
		$result = pg_prepare($pgconn,'insert_relnodes_wiwosm','INSERT INTO wiwosm SELECT $1 AS osm_id, $2 AS wikidata_ref, ST_Collect(way) AS way, $3 AS lang, $4 AS article, $5 AS anchor FROM (
			(SELECT way FROM planet_osm_point WHERE osm_id = ANY ($6) )
			) AS members');
		if ($result === false) exit();

		$query = "SELECT id,members,tags FROM planet_osm_rels WHERE array_to_string(tags,',') ~ 'wiki(pedia|data)' AND -id NOT IN ( SELECT osm_id FROM wiwosm WHERE osm_id<0 )";
		$result = pg_query($pgconn,$query);
		while ($row = pg_fetch_assoc($result)) {
			// if the relation has no members ignore it and try the next one
			if (!$row['members']) continue;
			$wikidata_ref = 0;
			$lang = '';
			$article = '';
			$anchor = '';

			$has_wikipedia_tag = false;
			$has_wikidata_tag = false;

			$tagscsv = str_getcsv(substr($row['tags'],1,-1),',','"');
			for($i=0; $i<count($tagscsv); $i+=2) {
				$key = $tagscsv[$i];
				if (!$has_wikipedia_tag && substr($key,0,9) == 'wikipedia') {
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

						$has_wikipedia_tag = true;
					}
				}
				if (!$has_wikidata_tag && $key == 'wikidata') {
					if (preg_match('/^Q(\d+)/', $tagscsv[$i+1], $matches)) {
						$wikidata_ref = intval($matches[1]);
						$has_wikidata_tag = true;
					}
				}
				// if found one wikipedia and wikidata tag -> stop looping the tags
				if ($has_wikipedia_tag && $has_wikidata_tag) break;
			}
			// if we found a wikipedia or wikidata tag we fetch all relation members
			if ($has_wikipedia_tag || $has_wikidata_tag) {
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
					pg_execute($pgconn,'insert_relways_wiwosm',array('-'.$row['id'],$wikidata_ref,$lang,$article,$anchor,'{'.$wayscsv.'}'));
				}
				if ($hasNodes) {
					pg_execute($pgconn,'insert_relnodes_wiwosm',array('-'.$row['id'],$wikidata_ref,$lang,$article,$anchor,'{'.$nodescsv.'}'));
				}
			}
		}
	}

	/**
	 * We want to work with real php arrays, so we have to process the hstore string
	 * @param string $hstore This is a string returned by a postgresql hstore column that looks like: '"foo"=>"bar", "baz"=>"blub", "lang"=>"article"' …
	 * @return array return a php array with languages as keys and articles as values
	 **/
	public static function hstoreToArray($hstore) {
		$ret_array = array();
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

	/**
	 * Believe it or not - in the year 2012 string encoding still sucks!
	 * @param string $str A string that is maybe not correct UTF-8 (reason is a bad urlencoding for example)
	 * @return string return a string that is definitly a valid UTF-8 string even if some characters were dropped
	 **/
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

	function queryWikidataLanguagesByLangArticle($lang, $article) {
		// if no lang or article is given, we can stop here
		if (!$lang || !$article) return false;

		// if the lang for example is fiu-vro the site is named fiu_vrowiki so we have to replace - by _
		$lang = str_replace('-','_',$lang).'wiki';

		if (!$this->prep_wikidata_by_lang_article->bind_param('ss', $lang, $article)) {
			$this->logMessage('bind_param failed with lang="'.$lang.'" and article="'.$article.'": '.$this->prep_wikidata_by_lang_article->error."\n", 1);
			return false;
		}

		if (!$this->prep_wikidata_by_lang_article->execute()) {
			$this->logMessage('wikidata query failed with lang="'.$lang.'" and article="'.$article.'": '.$this->prep_wikidata_by_lang_article->error."\n", 1);
			return false;
		}

		if (!$this->prep_wikidata_by_lang_article->store_result()) {
			$this->logMessage('wikidata query store result failed with lang="'.$lang.'" and article="'.$article."\"\n", 1);
			return false;
		}

		if ($this->prep_wikidata_by_lang_article->num_rows == 0) return false;

		if (!$this->prep_wikidata_by_lang_article->bind_result($wd_id, $ll_lang, $ll_title)) {
			$this->logMessage('bind_result failed with lastarticle='.$this->lastarticle.': '.$this->prep_wikidata_by_lang_article->error."\n", 1);
			return false;
		}

		$langarray = array();
		while ($this->prep_wikidata_by_lang_article->fetch()) {
			$langarray[str_replace('wiki', '', $ll_lang)] = $ll_title;
		}
		return array($wd_id, $langarray);
	}

	function queryWikidataLanguagesByWikidataref($wikidata_ref) {
		// if no $wikidata_ref is given, we can stop here
		if (!$wikidata_ref) return false;

		if (!$this->prep_wikidata_by_wikidata_ref->bind_param('s', $wikidata_ref)) {
			$this->logMessage('bind_param failed with wikidata_ref="'.$wikidata_ref.'": '.$this->prep_wikidata_by_wikidata_ref->error."\n", 1);
			return false;
		}

		if (!$this->prep_wikidata_by_wikidata_ref->execute()) {
			$this->logMessage('wikidata query failed with wikidata_ref="'.$wikidata_ref.'": '.$this->prep_wikidata_by_wikidata_ref->error."\n", 1);
			return false;
		}

		if (!$this->prep_wikidata_by_wikidata_ref->store_result()) {
			$this->logMessage('wikidata query store result failed with wikidata_ref="'.$wikidata_ref."\"\n", 1);
			return false;
		}

		if ($this->prep_wikidata_by_wikidata_ref->num_rows == 0) return false;

		if (!$this->prep_wikidata_by_wikidata_ref->bind_result($wd_id, $ll_lang, $ll_title)) {
			$this->logMessage('bind_result failed with lastarticle='.$this->lastarticle.': '.$this->prep_wikidata_by_wikidata_ref->error."\n", 1);
			return false;
		}

		$langarray = array();
		while ($this->prep_wikidata_by_wikidata_ref->fetch()) {
			$langarray[str_replace('wiki', '', $ll_lang)] = $ll_title;
		}
		return array($wd_id, $langarray);
	}

	function escape($str) {
		return str_replace(array('\\','"'),array('\\\\','\\"'),$str);
	}

	function insert_wiwosm_wikidata_languages($res) {
		if ($res) {
			$pgconn = $this->getPgConn();
			$wikidata_id = $res[0];
			foreach ($res[1] as $l => $a) {
				pg_execute($pgconn,'insert_wiwosm_wikidata_languages',array($wikidata_id, str_replace('_','-',$l), $a));
			}
			pg_execute($pgconn,'insert_wiwosm_wikidata_languages',array($wikidata_id,'wikidata','Q'.$wikidata_id));
		}
	}

	function map_wikidata_languages() {
		$mysqlconn = $this->getMysqlConn();
		$this->prep_wikidata_by_wikidata_ref = $mysqlconn->prepare('SELECT `ips_item_id`,`ips_site_id`,`ips_site_page`  FROM `wb_items_per_site` WHERE `ips_item_id` = ? ');

		$pgconn = $this->getPgConn();

		// delete cached wikidata_id before refetching them
		$result = pg_prepare($pgconn,'delete_wikidata_refs','DELETE FROM wiwosm_wikidata_languages WHERE wikidata_id = ANY ($1)');
		if ($result === false) exit();

		$result = pg_prepare($pgconn,'insert_wiwosm_wikidata_languages','INSERT INTO wiwosm_wikidata_languages (wikidata_id,lang,article) VALUES ($1,$2,$3)');
		if ($result === false) exit();

		// every wikidata_ref that is not present in wiwosm_wikidata_languages should get fetched from wikidata
		$sql = 'SELECT DISTINCT wikidata_ref FROM wiwosm WHERE wikidata_ref > 0 AND NOT EXISTS (SELECT 1 FROM wiwosm_wikidata_languages WHERE wiwosm_wikidata_languages.wikidata_id = wiwosm.wikidata_ref LIMIT 1)';

		if (!pg_query($pgconn,'BEGIN WORK') || !pg_query($pgconn,'DECLARE wikidatarefcur NO SCROLL CURSOR FOR '.$sql)) {
			$this->logMessage('Could not declare cursor wikidatarefcur'. "\n" . pg_last_error() . "\n", 1);
			exit();
		}

		$count = 0;

		// fetch from wikidatarefcur in steps of 1000 elements
		$result = pg_prepare($pgconn,'fetch_wikidatarefcur','FETCH 1000 FROM wikidatarefcur');
		if ($result === false) exit();

		$result = pg_execute($pgconn,'fetch_wikidatarefcur',array());

		$fetchcount = pg_num_rows($result);

		$this->logMessage('Get the first '.$fetchcount.' wikidatarefs:'.((microtime(true)-$this->start)/60)." min\n", 2);

		//we use a cursor loop just to be sure that memory consumption does not explode:
		while ($fetchcount > 0) {
			$wikidata_refs = pg_fetch_all_columns($result);

			pg_execute($pgconn,'delete_wikidata_refs',array('{'.implode(',',$wikidata_refs).'}'));
			foreach ($wikidata_refs as $wikidata_ref) {
				$this->insert_wiwosm_wikidata_languages($this->queryWikidataLanguagesByWikidataref($wikidata_ref));
			}
			$count += $fetchcount;
			$this->logMessage($count.' wikidatarefs processed:'.((microtime(true)-$this->start)/60)." min\n", 2);
			$result = pg_execute($pgconn,'fetch_wikidatarefcur',array());
			$fetchcount = pg_num_rows($result);
		}

		pg_query($pgconn,'CLOSE wikidatarefcur');
		pg_query($pgconn,'COMMIT WORK');

		// try to fastconnect the obvious rows
		$query = "UPDATE wiwosm SET wikidata_ref=wikidata_id FROM wiwosm_wikidata_languages WHERE wikidata_ref = 0 AND wiwosm.lang=wiwosm_wikidata_languages.lang AND wiwosm.article=wiwosm_wikidata_languages.article";
		$result = pg_query($pgconn,$query);

		// try to fetch wikidata_ref by language and article
		$this->prep_wikidata_by_lang_article = $mysqlconn->prepare('SELECT `ips_item_id`,`ips_site_id`,`ips_site_page`  FROM `wb_items_per_site` WHERE `ips_item_id` = (SELECT `ips_item_id` FROM `wb_items_per_site` WHERE `ips_site_id` = ? AND `ips_site_page` = ? LIMIT 1)');

		// every row in wiwosm with wikidata_ref=0 is an error and should get -1 or has no entries in wiwosm_wikidata_languages, yet
		$query = "SELECT lang,article FROM wiwosm WHERE wikidata_ref=0 ORDER BY lang,article";
		// lets update every row and use a cursor for that
		if (!pg_query($pgconn,'BEGIN WORK') || !pg_query($pgconn,'DECLARE updatelangcur NO SCROLL CURSOR FOR '.$query.' FOR UPDATE OF wiwosm')) {
			$this->logMessage('Could not declare cursor for updating language refs'. "\n" . pg_last_error() . "\n", 1);
			exit();
		}

		$langbefore = '';
		$articlebefore = '';

		$count = 0;

		// prepare some sql queries that are used very often:

		// this is to search for an entry in wiwosm_wikidata_languages table by given article and language
		$result = pg_prepare($pgconn,'get_wikidata_id','SELECT wikidata_id FROM wiwosm_wikidata_languages WHERE lang=$1 AND article=$2');
		if ($result === false) exit();

		// update the wikidata_ref column in wiwosm table by using the current row from updatelangcur cursor
		$result = pg_prepare($pgconn,'update_wiwosm_wikidata_ref','UPDATE wiwosm SET wikidata_ref=$1 WHERE CURRENT OF updatelangcur');
		if ($result === false) exit();

		$result = pg_prepare($pgconn,'fetch_next_updatelangcur','FETCH NEXT FROM updatelangcur');
		if ($result === false) exit();
		$result = pg_execute($pgconn,'fetch_next_updatelangcur',array());
		$fetchcount = pg_num_rows($result);

		while ($fetchcount == 1) {
			$row = pg_fetch_assoc($result);
			$article = str_replace('_',' ',stripcslashes(self::fixUTF8(urldecode($row['article']))));
			$lang = $row['lang'];
			if ($langbefore !== $lang || $articlebefore !== $article) {
				if ($langbefore !== $lang) {
					$this->logMessage('Lastlang was:'.$langbefore."\n".'Handled '.$count.' rows '.((microtime(true)-$this->start)/60)." min\n", 2);
				}
				$langbefore = $lang;
				$articlebefore = $article;
				$wikidata_id = '-1';
				$result = pg_execute($pgconn,'get_wikidata_id',array($lang, $article));
				if ($result && pg_num_rows($result) == 1) {
					// if we found an entry in our wiwosm_wikidata table we use that id to link
					$wikidata_id = pg_fetch_result($result,0,0);
				} else {
					// if there was no such entry we have to query the wikidata mysql db
					$this->insert_wiwosm_wikidata_languages($this->queryWikidataLanguagesByLangArticle($lang,$article));
				}
			}
			pg_execute($pgconn,'update_wiwosm_wikidata_ref',array($wikidata_id));
			if ($result === false) exit();
			$count += $fetchcount;
			$result = pg_execute($pgconn,'fetch_next_updatelangcur',array());
			$fetchcount = pg_num_rows($result);
		}
		pg_query($pgconn,'CLOSE updatelangcur');
		pg_query($pgconn,'COMMIT WORK');

		if ($this->prep_wikidata_by_lang_article) $this->prep_wikidata_by_lang_article->close();
		if ($this->prep_wikidata_by_wikidata_ref) $this->prep_wikidata_by_wikidata_ref->close();
	}

	function createWikidataLangTable() {
		$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm_wikidata_languages;
CREATE TABLE wiwosm_wikidata_languages (
	wikidata_id int NOT NULL,
	lang text,
	article text,
	PRIMARY KEY(lang, article)
);
CREATE INDEX wikidata_id_idx ON wiwosm_wikidata_languages (wikidata_id);
COMMIT;
EOQ;
		pg_query($this->getPgConn(),$query);
	}


	function createlinks($lang, $article, $geojson, $lang_hstore = '', $forceIWLLupdate = false) {
		// for every osm object with a valid wikipedia-tag print the geojson to file
		$filepath = $this->getFilePath($lang,$article);

		// we need no update of the Interwiki language links if there are no other languages in hstore given or
		// the file exists already and there is no force parameter given that forces to overwrite the existing links.
		// So we should create the Links, if the file does not exist already (and hstore is given) because it is new then
		$neednoIWLLupdate = ($lang_hstore == '') || (file_exists($filepath) && !$forceIWLLupdate);

		$handle = gzopen($filepath,'w');
		gzwrite($handle,$geojson);
		gzclose($handle);

		// check if we need an update of the Interwiki language links
		if ($neednoIWLLupdate) return true;

		// get the relativ filepath
		// $filepath = $this->getFilePath($lang,$article,true);

		$langarray = self::hstoreToArray($lang_hstore);
		// for every interwikilink do a hard link to the real file written above
		foreach ($langarray as $l => $a) {
			if ($l != $lang) {
				$linkpath = $this->getFilePath($l,$a);
				@unlink($linkpath);
				//symlink('../../'.$filepath,$linkpath);
				link($filepath,$linkpath);
				unset($linkpath);
			}
		}
		// free the memory
		unset($filepath,$handle,$geojson,$lang_hstore,$langarray);
		return true;
	}


	function updateOneObject($lang,$article) {
		$pgconn = $this->getPgConn();
		$articlefilter = '( tags @> $1::hstore ) OR ( tags @> $2::hstore ) OR ( tags @> $3::hstore ) OR ( tags @> $4::hstore ) OR ( tags @> $5::hstore ) OR ( tags @> $6::hstore )';
		$sql = 'SELECT '.self::simplifyGeoJSON.' FROM (
			( SELECT way FROM planet_osm_polygon WHERE '.$articlefilter.' )
			UNION ( SELECT way FROM planet_osm_line WHERE ( '.$articlefilter.' ) AND NOT EXISTS (SELECT 1 FROM planet_osm_polygon WHERE planet_osm_polygon.osm_id = planet_osm_line.osm_id) )
			UNION ( SELECT way FROM planet_osm_point WHERE '.$articlefilter.' )
			) AS wikistaff
			';
		pg_prepare($pgconn,'select_wikipedia_object',$sql);

		$a = $this->escape(str_replace('_',' ',$article));
		$aurl = urlencode(str_replace(' ','_',$a));
		$l = $this->escape(str_replace('_','-',$lang));
		$lurl = str_replace('-','_',$l);
		$params = array('"wikipedia:'.$l.'"=>"'.$a.'"',
				'"wikipedia"=>"'.$l.':'.$a.'"',
				'"wikipedia"=>"http://'.$lurl.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia"=>"https://'.$lurl.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia:'.$l.'"=>"http://'.$lurl.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia:'.$l.'"=>"https://'.$lurl.'.wikipedia.org/wiki/'.$aurl.'"');

		$result = pg_execute($pgconn,'select_wikipedia_object',$params);
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

		if ($result && pg_num_rows($result) == 1 ) {
			$row = pg_fetch_assoc($result);
			$this->createlinks($lang, $article, $row['geojson']);
		}
	}

	function processOsmItems() {
		$pgconn = $this->getPgConn();
		// to avoid problems with geometrycollections first dump all geometries and collect them again
		$sql = 'SELECT wikidata_ref, languages, geojson FROM
				( SELECT wikidata_ref, '.self::simplifyGeoJSON.' FROM  (
					SELECT wikidata_ref,(ST_Dump(way)).geom AS way FROM wiwosm WHERE wikidata_ref > 0
				) AS geomdump GROUP BY wikidata_ref) AS wiwosm_geom, (
					SELECT wikidata_id, hstore(array_agg(wiwosm_wikidata_languages.lang), array_agg(wiwosm_wikidata_languages.article)) AS languages FROM wiwosm_wikidata_languages GROUP BY wikidata_id
				) AS wikidata_languages
			WHERE wikidata_ref=wikidata_id';

		// this consumes just too mutch memory:
		/*
		$result = pg_query($conn, $sql);
		if (!$result) {
		$this->logMessage("Fail to fetch results from postgis \n", 1);
		exit;
		}
		*/

		// so we have to use a cursor because its too much data:
		if (!pg_query($pgconn,'BEGIN WORK') || !pg_query($pgconn,'DECLARE osmcur NO SCROLL CURSOR FOR '.$sql)) {
			$this->logMessage('Could not declare cursor'. "\n" . pg_last_error() . "\n", 1);
			exit();
		}


		$count = 0;

		// fetch from osmcur in steps of 1000 elements
		$result = pg_prepare($pgconn,'fetch_osmcur','FETCH 1000 FROM osmcur');
		if ($result === false) exit();

		$result = pg_execute($pgconn,'fetch_osmcur',array());

		$fetchcount = pg_num_rows($result);

		$this->logMessage('Get the first '.$fetchcount.' rows:'.((microtime(true)-$this->start)/60)." min\n", 2);

		//damn cursor loop:
		while ($fetchcount > 0) {
			while ($row = pg_fetch_assoc($result)) {

				$this->createlinks('wikidata', 'Q'.$row['wikidata_ref'], $row['geojson'], $row['languages']);
				// free the memory
				unset($row);
			}
			$count += $fetchcount;
			$this->logMessage($count.' results processed:'.((microtime(true)-$this->start)/60)." min\n", 2);
			$result = pg_execute($pgconn,'fetch_osmcur',array());
			$fetchcount = pg_num_rows($result);
		}

		pg_query($pgconn,'CLOSE osmcur');
		pg_query($pgconn,'COMMIT WORK');
	}

}
