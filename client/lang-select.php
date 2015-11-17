<?php

function translate($word,$lang)
{
include "kml-on-ol.i18n.php";
$withprefix="ts-kml-on-ol-".$word;
$a=$messages[$lang][$withprefix];
if (!isset($a)){$a=$messages['en'][$withprefix];}
#print_r ($messages);
#echo "<!---".$a."-| $withprefix -->";
return $a;
}

$uselang=addslashes(urldecode($_GET[lang]));

$langCapital=ucwords($uselang);

$alllangs=array("wikidata","aa","ab","ace","af","ak","als","am","an","ang","ar","arc","arz","ast","av","ay","az","ba",
"bar","bat-smg","bcl","be","be-x-old","bg","bh","bi","bm","bn","bo","bpy","br","bs","bug","bxr","ca","cbk-zam","cdo",
"ce","ceb","ch","cho","chr","chy","ckb","co","cr","crh","cs","csb","cu","cv","cy","da","de","diq","dsb","dv","dz","ee",
"el","eml","en","en-simple","eo","es","et","eu","ext","fa","ff","fi","fiu-vro","fj","fo","fr","frp","fur","fy","ga",
"gan","gd","gl","glk","gn","got","gu","gv","ha","hak","haw","he","hi","hif","ho","hr","hsb","ht","hu","hy","hz","ia",
"id","ie","ig","ii","ik","ilo","io","is","it","iu","ja","jbo","jv","ka","kaa","kab","kg","ki","kj","kk","kl","km",
"kn","ko","kr","ks","ksh","ku","kv","kw","ky","la","lad","lb","lbe","lg","li","lij","lmo","ln","lo","lt","lv",
"map-bms","mdf","mg","mh","mhr","mi","mk","ml","mn","mo","mr","ms","mt","mus","mwl","my","myv","mzn","na","nah",
"nap","nds","nds-nl","ne","new","ng","nl","nn","no","nostalgia","nov","nrm","nv","ny","oc","om","or",
"os","pa","pag","pam","pap","pcd","pdc","pi","pih","pl","pms","pnb","pnt","ps","pt","qu","rm","rmy","rn","ro",
"roa-rup","roa-tara","ru","rw","sa","sah","sc","scn","sco","sd","se","sg","sh","si","simple","sk","sl","sm","sn",
"so","sq","sr","srn","ss","st","stq","su","sv","sw","szl","ta","te","tet","tg","th","ti","tk","tl","tlh","tn","to",
"tokipona","tpi","tr","ts","tt","tum","tw","ty","udm","ug","uk","ur","uz","ve","vec","vi","vls","vo","wa","war","wo",
"wuu","xal","xh","yi","yo","za","zea","zh","zh-classical","zh-min-nan","zh-yue","zu");
 #Wikipedias with >1000 articles
$alllangs_red=array("wikidata","ace","af","als","am","an","ang","ar","arc","arz","ast","ay","az","bar","bat-smg","bcl","be",
"be-x-old","bg","bh","bn","bo","bpy","br","bs","ca","cbk-zam","ceb","ckb","co","crh","cs","csb","cv","cy","da","de","diq","dv",
"el","eml","en","eo","es","et","eu","ext","fa","fi","fiu-vro","fj","fo","fr","frp","fur","fy","ga","gan","gd","gl","glk","gn",
"gu","gv","hak","haw","he","hi","hif","hr","hsb","ht","hu","hy","ia","id","ie","ilo","io","is","it","ja","jbo","jv","ka","kk",
"km","kn","ko","krc","ksh","ku","kv","kw","ky","la","lad","lb","li","lij","lmo","ln","lt","lv","map-bms","mg","mhr","mi","mk",
"ml","mn","mr","ms","mt","my","myv","mzn","nah","nap","nds","nds-nl","ne","new","nl","nn","no","nov","nrm","nv","oc","os","pa",
"pag","pam","pcd","pdc","pi","pl","pms","pnb","ps","pt","qu","rm","ro","roa-rup","roa-tara","ru","sa","sah","sc","scn","sco",
"se","sg","sh","si","simple","sk","sl","so","sq","sr","stq","su","sv","sw","szl","ta","te","tg","th","tk","tl","to","tr","tt",
"ug","uk","ur","uz","vec","vi","vls","vo","wa","war","wo","wuu","xal","yi","yo","zh","zh-classical","zh-min-nan","zh-yue"); 


include "./cldr/LanguageNames".$langCapital.".php";?>
<b>
<?php echo translate('languages',$uselang);?>
</b><br />
<?php
echo '<select class="menuSelectlist" name="top5" size="5"> 
<option value=""> '.translate('all',$uselang).'  </option>';
foreach ($alllangs_red as $key => $la) {
if ($la==$uselang) {$select=" selected ";} else  {$select="";}
echo '<option '.$select.' value="'.$la.'">'.$names[$la]."\t(".$la.")</option>\n";
}
echo '</select>';
?>

<p />

<input class="menuSelect" type="checkbox" name="thumbs" value="thumbs"/>            
<b><?php echo translate('thumbnails',$uselang);?></b>

<br>
<input class="menuSelect2" type="checkbox" name="coats" value="coats"/>
<b><?php echo translate('coat-of-arms',$uselang);?></b>
</select>

 
