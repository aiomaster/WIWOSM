 <?php

/**
 * Wiwosm main class
 * @author Christoph Wagner
 * @version 1.5
 */

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

	private $pgconn;

	private $mysqliconn;
	private $prep_mysql;
	private $lastlang;
	private $lastarticle;

	private $start;

	/**
	 * This is what to do on creating a Wiwosm object
	 * (start timer, parse ini file for db connection … )
	 **/
	function __construct($dbconn = true, $mysqlconn = false) {
		$this->start = microtime(true);
		$this->pgconn = false;
		$this->mysqliconn = false;
		if ($dbconn) {
			$this->openPgConnection();
			if ($mysqlconn) {
				$this->toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
				$this->openMysqlConnection();
			}
		}
		$this->json_path = self::JSON_PATH;
	}

	/**
	 * This is what to do before exiting this program
	 * (output time and memory consumption, close all db connections … )
	 **/
	function __destruct() {
		echo 'Execution time: '.((microtime(true)-$this->start)/60)."min\n";
		echo 'Peak memory usage: '.(memory_get_peak_usage(true)/1024/1024)."MB\n";
		if ($this->pgconn) {
			pg_close($this->pgconn);
		}
		if ($this->mysqliconn) {
			if ($this->prep_mysql) $this->prep_mysql->close();
			$this->mysqliconn->close();
		}
	}

	/**
	 * Open a postgresql db connection
	 **/
	function openPgConnection() {
		// open psql connection
		$this->pgconn = pg_connect('host=sql-mapnik dbname=osm_mapnik');
		// check for connection error
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);
		//pg_set_client_encoding($this->pgconn, UNICODE);
	}

	/**
	 * Open a mysql db connection to wikidata
	 **/
	function openMysqlConnection() {
		$this->mysqliconn = new mysqli('sql-s5', $this->toolserver_mycnf['user'], $this->toolserver_mycnf['password'], 'wikidatawiki_p');
		if (mysqli_connect_errno()) {
			echo 'Mysql connection failed: '.mysqli_connect_error()."\n";
			exit();
		}
		$this->prep_mysql = $this->mysqliconn->prepare('SELECT `ips_item_id`,`ips_site_id`,`ips_site_page`  FROM `wb_items_per_site` WHERE `ips_item_id` = (SELECT `ips_item_id` FROM `wb_items_per_site` WHERE `ips_site_id` = ? AND `ips_site_page` = ? LIMIT 1)');
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
	function getFilePath($lang, $article, $relativ = false) {
		//$hash = md5($lang.str_replace('_',' ',$article));
		// use fnvhash because its much faster than md5
		$article = str_replace('_',' ',$article);
		$hash = $this->fnvhash($lang.$article);
		$relpath = substr($hash,0,2).'/'.substr($hash,0,4);
		$fullpath = $this->json_path.'/'.$relpath;
		$path = ($relativ) ? $relpath : $fullpath;
		if (!file_exists($fullpath)) @mkdir($fullpath, 0755, true);
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
		error_log($l."\t".$a."\n",3,'/home/master/unknown.csv');
	}

	/**
	 * Write all broken languages with their articles into a nice html logfile
	 **/
	function logUnknown() {
		$htmlrows = '';
		// get all broken languages
		$query = 'SELECT osm_id,lang,article,array_agg(ST_GeometryType(way)) AS geomtype FROM wiwosm WHERE wikidata_ref = -1 GROUP BY osm_id,lang,article';
		$result = pg_query($this->pgconn,$query);
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
		$fh = fopen('/home/master/public_html/wiwosmlog/broken.html','w');
		fwrite($fh, $html);
		fclose($fh);
	}

	/**
	 * Write all broken languages with their articles into a nice JSON logfile
	 **/
	function logUnknownJSON() {
		// get all broken languages
		$query = 'SELECT DISTINCT ON (osm_id) osm_id,lang,article,geomtype,iso2,name FROM ( SELECT osm_id,lang,article,array_agg(ST_GeometryType(way)) AS geomtype, ST_Transform(ST_SetSRID(ST_Extent(way),900913),4326) AS extent FROM wiwosm WHERE wikidata_ref = -1 GROUP BY osm_id,lang,article ) AS w LEFT JOIN wiwosm_tm_world_borders_simple ON ST_Intersects(extent, ST_SetSRID(geom,4326))';
		$result = pg_query($this->pgconn,$query);
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
		$handle = gzopen('/home/master/public_html/wiwosmlog/broken.json.gz','w');
		gzwrite($handle,$json);
		gzclose($handle);
	}

	/**
	 * We have to make sure that the full update process did work and so we simply test, if there are enough files in our update directory.
	 * If this is the case we remove the _old dir, move the current dir to _old and the _update to current dir.
	 **/
	function testAndRename() {
		//$countFiles = system('ls -RU1 --color=never '.$json_path.' | wc -l');
		echo 'Execution time: '.((microtime(true)-$this->start)/60)."min\n";
		echo 'Counting generated files …'."\n";
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
CREATE INDEX article_lang_index ON wiwosm (article, lang ASC); -- index on articles and languages
EOQ;
		pg_query($this->pgconn,$query);
	}

	/**
	 * Throw away the wiwosm table and rebuild it from the mapnik db
	 **/
	function updateWiwosmDB() {
$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm;
CREATE TABLE wiwosm AS (
SELECT osm_id, way, ( CASE WHEN strpos(wikipedia,':')>0 THEN lower(split_part(wikipedia, ':', 1)) ELSE '' END ) AS lang, split_part(substring(wikipedia from position(':' in wikipedia)+1),'#', 1) AS article, split_part(wikipedia,'#', 2) AS anchor FROM (
SELECT osm_id, way,
regexp_replace(
  substring(
    concat(
      substring(array_to_string(akeys(tags),',') from 'wikipedia:?[^,]*'), -- this is the tagname for example "wikipedia" or "wikipedia:de"
      ':',
      regexp_replace(
        tags->substring(array_to_string(akeys(tags),',') from 'wikipedia:?[^,]*'), -- get the first wikipedia tag from hstore
        '^(?:https?://)?(\\w*)\\.wikipedia\\.org/wiki/(.*)$', -- matches if the value is a wikipedia url (otherwise it is an article)
        '\\1:\\2' -- get the domain prefix and use it as language key followed by the article name
      )
    ) -- resulting string is for example wikipedia:de:Dresden
    from 11 -- remove the "wikipedia:" prefix
  ),
  '^(\\w*:)\\1','\\1' -- it is possible that there is such a thing like "de:de:Artikel" left if there was a tag like "wikipedia:de=http://de.wikipedia.org/wiki/Artikel", so remove double language labels
) AS "wikipedia"
FROM (
( SELECT osm_id, tags, way FROM planet_point WHERE strpos(concat(',',array_to_string(akeys(tags),',')),',wikipedia')>0 )
UNION ( SELECT osm_id, tags, way FROM planet_line WHERE strpos(concat(',',array_to_string(akeys(tags),',')),',wikipedia')>0 AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) ) -- we don't want LineStrings that exist as polygon, yet
UNION ( SELECT osm_id, tags, way FROM planet_polygon WHERE strpos(concat(',',array_to_string(akeys(tags),',')),',wikipedia')>0 )
) AS wikistaff
) AS wikiobjects
-- WHERE strpos(wikipedia,':')>0 -- remove tags with no language defined for example wikipedia=Artikel
ORDER BY article,lang ASC
)
;
ALTER TABLE wiwosm ADD COLUMN wikidata_ref integer DEFAULT 0;
ALTER TABLE wiwosm OWNER TO master;
GRANT ALL ON TABLE wiwosm TO master;
GRANT SELECT ON TABLE wiwosm TO public;
UPDATE wiwosm SET wikidata_ref=-1 WHERE lang = ANY (ARRAY['','http','subject','name','operator','related','sculptor','architect','maker']); -- we know that there could not be a language reference in Wikipedia for some lang values.
COMMIT;
EOQ;

		pg_query($this->pgconn,$query);
		if($e = pg_last_error()) {
			trigger_error($e, E_USER_ERROR);
			exit();
		} else {
			echo 'wiwosm DB basic table build in '.((microtime(true)-$this->start)/60)." min\nStarting additional relation adding …\n";
			$this->addMissingRelationObjects();
			echo 'Missing Relations added '.((microtime(true)-$this->start)/60)." min\nCreate Indices and link articleslanguages …\n";
			$this->createIndices();
			$this->linkarticlelanguages();
			echo 'wiwosm DB upgraded in '.((microtime(true)-$this->start)/60)." min\n";
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

			$result = pg_execute($this->pgconn,'get_existing_member_relations',array('{'.$newrelscsv.'}'));
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

				$res = pg_execute($this->pgconn,'get_member_relations_planet_rels',array('{'.$othersubrelscsv.'}'));
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
	 * But we need them, because they can be tagged with wikipedia tags so we have to look in the planet_rels table, that is a preprocessing table for osm2pgsql that holds all the information we need, but in an ugly format.
	 * So we have to search in the tags and members arrays if we can find something usefull, get the objects from the mapnik tables and store it in wiwosm.
	 **/
	function addMissingRelationObjects() {
		// prepare some often used queries:

		// search for existing relations that are build in osm2pgsql default scheme ( executed in getAllMembers function!)
		$result = pg_prepare($this->pgconn,'get_existing_member_relations','SELECT DISTINCT osm_id FROM (
			(SELECT osm_id FROM planet_point WHERE osm_id = ANY ($1))
			UNION (SELECT osm_id FROM planet_line WHERE osm_id = ANY ($1))
			UNION (SELECT osm_id FROM planet_polygon WHERE osm_id = ANY ($1))
		) AS existing');
		if ($result === false) exit();

		// fetch all members of all subrelations and combine them to one csv string ( executed in getAllMembers function!)
		$result = pg_prepare($this->pgconn,'get_member_relations_planet_rels','SELECT members FROM planet_rels WHERE id = ANY ($1)');
		if ($result === false) exit();

		// insert ways and polygons in wiwosm
		$result = pg_prepare($this->pgconn,'insert_relways_wiwosm','INSERT INTO wiwosm SELECT $1 AS osm_id, ST_Collect(way) AS way , $2 AS lang, $3 AS article, $4 AS anchor FROM (
			(SELECT way FROM planet_polygon WHERE osm_id = ANY ($5) )
			UNION ( SELECT way FROM planet_line WHERE osm_id = ANY ($5) AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) )
			) AS members');
		if ($result === false) exit();

		// insert nodes in wiwosm
		$result = pg_prepare($this->pgconn,'insert_relnodes_wiwosm','INSERT INTO wiwosm SELECT $1 AS osm_id, ST_Collect(way) AS way , $2 AS lang, $3 AS article, $4 AS anchor FROM (
			(SELECT way FROM planet_point WHERE osm_id = ANY ($5) )
			) AS members');
		if ($result === false) exit();

		$query = "SELECT id,members,tags FROM planet_rels WHERE strpos(array_to_string(tags,','),'wikipedia')>0 AND -id NOT IN ( SELECT osm_id FROM wiwosm WHERE osm_id<0 )";
		$result = pg_query($this->pgconn,$query);
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
							pg_execute($this->pgconn,'insert_relways_wiwosm',array('-'.$row['id'],$lang,$article,$anchor,'{'.$wayscsv.'}'));
						}
						if ($hasNodes) {
							pg_execute($this->pgconn,'insert_relnodes_wiwosm',array('-'.$row['id'],$lang,$article,$anchor,'{'.$nodescsv.'}'));
						}

						// found a wikipedia tag so stop looping the tags
						break;
					}

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


	function queryInterWikiLanguages($lang, $article) {
		// if no lang or article is given, we can stop here
		if (!$lang || !$article) return false;
		//$this->lastarticle = str_replace(array(' ','\''),array('_','\\\''),$article);
		$this->lastarticle = str_replace(' ','_',$article);
		// just do a new connection if we get another lang than in loop before
		if ($this->lastlang!=$lang) {
			echo 'Try new lang:'.$lang."\n";
			$this->lastlang=$lang;
			if ($this->mysqliconn) {
				if ($this->prep_mysql) $this->prep_mysql->close();
				$this->mysqliconn->close();
			}
			// if the lang for example is fiu-vro the table is named fiu_vro so we have to replace - by _
			$lang = str_replace('-','_',$lang);
			$this->mysqliconn = new mysqli($lang.'wiki-p.db.toolserver.org', $this->toolserver_mycnf['user'], $this->toolserver_mycnf['password'], $lang.'wiki_p');
			if ($this->mysqliconn->connect_error) {
				//$this->logUnknownLang($lang,$article);
				// return that we should skip this lang because there are errors
				return false;
			} else {
				//connection established but does the database and the tables exist?
				$tableres = $this->mysqliconn->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \''.$lang.'wiki_p\' AND table_name IN (\'langlinks\', \'page\')');
				$tablecount = $tableres->fetch_row();
				if ( $tablecount[0] != '2' ) return false;
				// no error -> prepare sql
				$this->prep_mysql = $this->mysqliconn->prepare('SELECT `ll_lang`,`ll_title` FROM `'.$lang.'wiki_p`.`langlinks` WHERE `ll_from` =(SELECT `page_id` FROM `'.$lang.'wiki_p`.`page` WHERE `page_namespace`=0 AND `page_is_redirect`=0 AND `page_title` = ? LIMIT 1) LIMIT 300');
				// if we could not prepare the select statement we should skip this lang
				if (!$this->prep_mysql) return false;
				if (!$this->prep_mysql->bind_param('s', $this->lastarticle)) {
					echo 'bind_param failed with lastarticle='.$this->lastarticle.': '.$this->prep_mysql->error."\n";
					return false;
				}
			}
		}
		try {
			if ($this->prep_mysql)
			       	$this->prep_mysql->execute();
			else {
				echo 'article: '.$this->lastarticle."\n".'lang: '.$lang."\n--\n";
				return false;
			}
			if (!$this->prep_mysql->bind_result($ll_lang,$ll_title)) {
				echo 'bind_result failed with lastarticle='.$this->lastarticle.': '.$this->prep_mysql->error."\n";
				return false;
			}
		} catch (Exception $e) {
			echo $e->getMessage()."\n".'article: '.$this->lastarticle."\n".'lang: '.$lang."\n--\n";
		}
		$langarray = array();
		while ($this->prep_mysql->fetch()) {
			$langarray[$ll_lang] = str_replace('_',' ',$ll_title);
		}
		return $langarray;
	}

	function queryWikidataLanguages($lang, $article) {
		// if no lang or article is given, we can stop here
		if (!$lang || !$article) return false;

		// if the lang for example is fiu-vro the site is named fiu_vrowiki so we have to replace - by _
		$lang = str_replace('-','_',$lang).'wiki';

		if (!$this->prep_mysql->bind_param('ss', $lang, $article)) {
			echo 'bind_param failed with lang="'.$lang.'" and article="'.$article.'": '.$this->prep_mysql->error."\n";
			return false;
		}

		if (!$this->prep_mysql->execute()) {
			echo 'wikidata query failed with lang="'.$lang.'" and article="'.$article.'": '.$this->prep_mysql->error."\n";
			return false;
		}

		if (!$this->prep_mysql->store_result()) {
			echo 'wikidata query store result failed with lang="'.$lang.'" and article="'.$article."\"\n";
			return false;
		}

		if ($this->prep_mysql->num_rows == 0) return false;

		if (!$this->prep_mysql->bind_result($wd_id, $ll_lang, $ll_title)) {
			echo 'bind_result failed with lastarticle='.$this->lastarticle.': '.$this->prep_mysql->error."\n";
			return false;
		}

		$langarray = array();
		while ($this->prep_mysql->fetch()) {
			$langarray[str_replace('wiki', '', $ll_lang)] = $ll_title;
		}
		return array($wd_id, $langarray);
	}

	function escape($str) {
		return str_replace(array('\\','"'),array('\\\\','\\"'),$str);
	}

	function linkarticlelanguages() {
		// try to fastconnect the obvious rows
		$query = "UPDATE wiwosm SET wikidata_ref=wikidata_id FROM wiwosm_wikidata WHERE (lang=lang_origin AND article=article_origin) OR (languages @> hstore(lang,article))";
		$result = pg_query($this->pgconn,$query);

		echo 'Could fastlink '.pg_affected_rows($result).' rows '.((microtime(true)-$this->start)/60)." min\n";

		$query = "SELECT lang,article FROM wiwosm WHERE wikidata_ref=0 ORDER BY lang,article";

		// lets update every row and use a cursor for that
		if (!pg_query($this->pgconn,'BEGIN WORK') || !pg_query($this->pgconn,'DECLARE updatelangcur NO SCROLL CURSOR FOR '.$query.' FOR UPDATE OF wiwosm')) {
			echo 'Could not declare cursor for updating language refs'. "\n" . pg_last_error() . "\n";
			exit();
		}

		$langbefore = '';
		$articlebefore = '';

		$count = 0;

		// prepare some sql queries that are used very often:

		// this is to search for an entry in wiwosm_wiki_ll table by given article and language
		$result = pg_prepare($this->pgconn,'get_wikidata_id','SELECT wikidata_id FROM wiwosm_wikidata WHERE languages @> $1::hstore');
		if ($result === false) exit();

		// insert a new line in wiwosm_wikidata. We have to give the datatype for the text[][] explicit here so we can't use pg_prepare
		$result = pg_query($this->pgconn,'PREPARE insert_wiwosm_wikidata (int,text,text,text[][]) AS INSERT INTO wiwosm_wikidata (wikidata_id,lang_origin,article_origin,languages) VALUES ($1,$2,$3,hstore($4))');
		if ($result === false) exit();

		// update the wikidata_ref column in wiwosm table by using the current row from updatelangcur cursor
		$result = pg_prepare($this->pgconn,'update_wiwosm_wikidata_ref','UPDATE wiwosm SET wikidata_ref=$1 WHERE CURRENT OF updatelangcur');
		if ($result === false) exit();

		$result = pg_prepare($this->pgconn,'fetch_next_updatelangcur','FETCH NEXT FROM updatelangcur');
		if ($result === false) exit();
		$result = pg_execute($this->pgconn,'fetch_next_updatelangcur',array());
		$fetchcount = pg_num_rows($result);

		while ($fetchcount == 1) {
			$row = pg_fetch_assoc($result);
			$article = str_replace('_',' ',stripcslashes(self::fixUTF8(urldecode($row['article']))));
			$lang = $row['lang'];
			if ($langbefore !== $lang || $articlebefore !== $article) {
				if ($langbefore !== $lang) {
					echo 'Lastlang was:'.$langbefore."\n".'Handled '.$count.' rows '.((microtime(true)-$this->start)/60)." min\n";
				}
				$langbefore = $lang;
				$articlebefore = $article;
				$wikidata_id = '-1';
				$params = array('"'.$this->escape($lang).'"=>"'.$this->escape($article).'"');
				$result = pg_execute($this->pgconn,'get_wikidata_id',$params);
				if ($result && pg_num_rows($result) == 1) {
					// if we found an entry in our wiwosm_wikidata table we use that id to link
					$wikidata_id = pg_fetch_result($result,0,0);
				} else {
					// if there was no such entry we have to query the wikidata mysql db
					$langarray = $this->queryWikidataLanguages($lang,$article);
					if ($langarray !== false) {
						$wikidata_id = $langarray[0];
						$hstorestring = '{';
						foreach ($langarray[1] as $l => $a) {
							$hstorestring .= '{"'.$this->escape(str_replace('_','-',$l)).'","'.$this->escape($a).'"},';
						}
						$hstorestring .= '{"wikidata","Q'.$wikidata_id.'"}}';
						pg_execute($this->pgconn,'insert_wiwosm_wikidata',array($wikidata_id,$lang,$article,$hstorestring));
					}
				}
			}
			pg_execute($this->pgconn,'update_wiwosm_wikidata_ref',array($wikidata_id));
			if ($result === false) exit();
			$count += $fetchcount;
			$result = pg_execute($this->pgconn,'fetch_next_updatelangcur',array());
			$fetchcount = pg_num_rows($result);
		}
		pg_query($this->pgconn,'CLOSE updatelangcur');
		pg_query($this->pgconn,'COMMIT WORK');
	}

	function createLangTable() {
		$query = <<<EOQ
BEGIN;
DROP TABLE IF EXISTS wiwosm_wikidata;
CREATE TABLE wiwosm_wikidata (
	wikidata_id int PRIMARY KEY,
	lang_origin text,
	article_origin text,
	languages hstore
);
ALTER TABLE wiwosm_wikidata OWNER TO master;
GRANT ALL ON TABLE wiwosm_wikidata TO master;
GRANT SELECT ON TABLE wiwosm_wikidata TO public;
CREATE INDEX languages_idx ON wiwosm_wikidata USING GIST (languages);
COMMIT;
EOQ;
		pg_query($this->pgconn,$query);
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
		$articlefilter = '( tags @> $1::hstore ) OR ( tags @> $2::hstore ) OR ( tags @> $3::hstore ) OR ( tags @> $4::hstore ) OR ( tags @> $5::hstore ) OR ( tags @> $6::hstore )';
		$sql = 'SELECT '.self::simplifyGeoJSON.' FROM (
			( SELECT way FROM planet_polygon WHERE '.$articlefilter.' )
			UNION ( SELECT way FROM planet_line WHERE ( '.$articlefilter.' ) AND NOT EXISTS (SELECT 1 FROM planet_polygon WHERE planet_polygon.osm_id = planet_line.osm_id) )
			UNION ( SELECT way FROM planet_point WHERE '.$articlefilter.' )
			) AS wikistaff
			';
		pg_prepare($this->pgconn,'select_wikipedia_object',$sql);

		$a = $this->escape($article);
		$aurl = urlencode(str_replace(' ','_',$a));
		$l = $this->escape($lang);
		$params = array('"wikipedia:'.$l.'"=>"'.$a.'"',
				'"wikipedia"=>"'.$l.':'.$a.'"',
				'"wikipedia"=>"http://'.$l.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia"=>"https://'.$l.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia:'.$l.'"=>"http://'.$l.'.wikipedia.org/wiki/'.$aurl.'"',
				'"wikipedia:'.$l.'"=>"https://'.$l.'.wikipedia.org/wiki/'.$aurl.'"');

		$result = pg_execute($this->pgconn,'select_wikipedia_object',$params);
		if($e = pg_last_error()) trigger_error($e, E_USER_ERROR);

		if ($result && pg_num_rows($result) == 1 ) {
			$row = pg_fetch_assoc($result);
			$this->createlinks($lang, $article, $row['geojson']);
		}
	}

	function processOsmItems() {
		// to avoid problems with geometrycollections first dump all geometries and collect them again
		$sql = 'SELECT lang_origin, article_origin, languages, geojson FROM ( SELECT wikidata_ref,'.self::simplifyGeoJSON.' FROM  (SELECT wikidata_ref,(ST_Dump(way)).geom AS way FROM wiwosm WHERE wikidata_ref != -1 ) AS geomdump GROUP BY wikidata_ref ) AS wiwosm_refs, wiwosm_wikidata WHERE wiwosm_refs.wikidata_ref = wiwosm_wikidata.wikidata_id';

		// this consumes just too mutch memory:
		/*
		$result = pg_query($conn, $sql);
		if (!$result) {
		echo "Fail to fetch results from postgis \n";
		exit;
		}
		*/

		// so we have to use a cursor because its too much data:
		if (!pg_query($this->pgconn,'BEGIN WORK') || !pg_query($this->pgconn,'DECLARE osmcur NO SCROLL CURSOR FOR '.$sql)) {
			echo 'Could not declare cursor'. "\n" . pg_last_error() . "\n";
			exit();
		}


		$count = 0;

		// fetch from osmcur in steps of 1000 elements
		$result = pg_prepare($this->pgconn,'fetch_osmcur','FETCH 1000 FROM osmcur');
		if ($result === false) exit();

		$result = pg_execute($this->pgconn,'fetch_osmcur',array());

		$fetchcount = pg_num_rows($result);

		echo 'Get the first '.$fetchcount.' rows:'.((microtime(true)-$this->start)/60)." min\n";

		//damn cursor loop:
		while ($fetchcount > 0) {
			while ($row = pg_fetch_assoc($result)) {

				$this->createlinks($row['lang_origin'], $row['article_origin'], $row['geojson'], $row['languages']);
				// free the memory
				unset($row);
			}
			$count += $fetchcount;
			echo $count.' results processed:'.((microtime(true)-$this->start)/60)." min\n";
			$result = pg_execute($this->pgconn,'fetch_osmcur',array());
			$fetchcount = pg_num_rows($result);
		}

		pg_query($this->pgconn,'CLOSE osmcur');
		pg_query($this->pgconn,'COMMIT WORK');
	}

}
?>
