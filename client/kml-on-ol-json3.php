<html>
	<head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8">
		<title>Wikipedia on OpenStreetMap</title>
		
		

<?php
#Contributors: Kolossos, Peter Körner, Alexrk2, Magnus Manske, master 
#Supporters:  river, Raymond, 32X, Markus B., ALE!, Stefan Kühn, dispenser, ...

require_once ( "geo_param.php" ) ;
include "mapsources.php" ;


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

function getFilePath($key) {
	$hash = md5($key);
	$path = 'http://toolserver.org/~kolossos/openlayers/master-store/'.substr($hash,0,2).'/'.substr($hash,0,4);
	mkdir($path, 0755, true);
	return $path.'/'.$hash.'.geojson';
}


$lang=addslashes(urldecode($_GET[lang]));
$uselang=addslashes(urldecode($_GET[uselang]));
if ($uselang=="") {$uselang=$lang;}

$thumbs=addslashes($_GET[thumbs]);
if (($thumbs=="no") or ($thumbs=="yes")) {$thumbsinsert=", 'thumbs' : '$thumbs'";} else {$thumbsinsert=", 'thumbs' : '0'";}
$pop=addslashes($_GET[pop]);
if (($pop<>"")) {$popinsert=", 'pop' : '$pop'";} 
$style=addslashes($_GET[style]);
if (($style<>"")) {$styleinsert=", 'style' : '$style'";}
$photo=addslashes($_GET[photo]);
if (($photo=="no") or ($photo=="yes")) {$photoinsert=", 'photo' : '$photo'";}
$source=addslashes($_GET[source]);
if (($source<>"")) {$sourceinsert=", 'source' : '$source'";} 
$notsource=addslashes($_GET[notsource]);
if (($notsource<>"")) {$notsourceinsert=", 'notsource' : '$notsource'";} 
$secure=addslashes($_GET[secure]);
if ($secure==1) {$secureinsert=", 'secure' : '1'";} 

$title=urldecode($_GET[title]);
$action=addslashes(urldecode($_GET[action]));

if ( isset ( $_REQUEST['params'] ) ) {
	$p = new geo_param(  $_REQUEST['params'] , "Dummy" ); ;
	$x = $p->londeg ;
	$y = $p->latdeg ;

	$type = $p->type ; 
	$dim= $p->dim ;  

}

$theparams =$_REQUEST['params'];
$typeleftcut = strstr($theparams,"type:");
$type = substr ($typeleftcut, 5 );
if (strpos($type,"(")>0) {$type = substr($type,0,strpos($type,"("));}
if (strpos($type,"_")>0) {$type = substr($type,0,strpos($type,"_"));}

$default_scale = array(
					'country'   =>  10000000, # 10 mill
					'satellite' =>  10000000, # 10 mill
					'state'     =>   3000000, # 3 mill
					'adm1st'    =>   1000000, # 1 mill
					'adm2nd'    =>    300000, # 300 thousand
					'adm3rd'    =>    100000, # 100 thousand
					'city'      =>    100000, # 100 thousand
					'isle'      =>    100000, # 100 thousand
					'mountain'  =>    100000, # 100 thousand
					'river'     =>    100000, # 100 thousand
					'waterbody' =>    100000, # 100 thousand
					'event'     =>     50000, # 50 thousand
					'forest'    =>     50000, # 50 thousand
					'glacier'   =>     50000, # 50 thousand
					'airport'   =>     30000, # 30 thousand
					'railwaystation' =>     10000, # 10 thousand
					'edu'       =>     10000, # 10 thousand
					'pass'      =>     10000, # 10 thousand
					'landmark'  =>     10000  # 10 thousand
				);
$zoomtype=18 - ( round(log($default_scale[$type],2) - log(1693,2)) );

$dimleftcut = strstr($theparams,"dim:");
$dim = substr ($dimleftcut, 4 );
if (strpos($dim,"(")>0) {$dim = substr($dim,0,strpos($dim,"("));}
if (strpos($dim,"_")>0) {$dim = substr($dim,0,strpos($dim,"_"));}
$dim = str_replace(array("km","m"),array("000",""),$dim);

if ($dim>0)
{$zoomtype=18 - ( round(log($dim/0.1,2) - log(1693,2)) );}

if ($zoomtype>18) {$zoom=18;$zoomtype=18;}
if ($zoomtype>2 ) {$zoom=$zoomtype;} else {$zoom=12;}

$position= "  var zoom = $zoom;";
if ($x<>""){$position.="
		args.lon = $x;
		args.lat = $y; ";}

echo "<!--- //position:".$position."\n dim:".$dim."\n zoomtype:".$zoomtype." --->\n";

?>
		<script src="http://toolserver.org/~osm/libs/jquery/latest/jquery-min.js" type="text/javascript"></script>
		<script src="http://toolserver.org/~osm/libs/openlayers/latest/OpenLayers.js" type="text/javascript"></script>
		<script src="http://toolserver.org/~osm/libs/openstreetmap/latest/OpenStreetMap.js" type="text/javascript"></script>
		<script src="./OpenLayers/lib/OpenLayers/Lang/<?php echo $uselang;?>.js" type="text/javascript"></script>
		
		<script type="text/javascript">
			// map object
			var map;
			var poiLayerHttp;
			var pois;
			var osmLabelsLang;
			var forcelocal;
		        
	      <?php echo "var lang = '$lang';";?>
		if(lang=="hsb"||lang=="ru") {forcelocal=true;} else {forcelocal=false;}



			// initiator
			function init()
			{
				OpenLayers.Lang.setCode('<?php echo $uselang;?>');
				var urlRegex = new RegExp('^http://([abc]).www.toolserver.org/tiles/([^/]+)/(.*)$');
				
				// show an error image for missing tiles
				OpenLayers.Util.onImageLoadError = function()
				{
					if(urlRegex.test(this.src))
					{
						var style = RegExp.$2;
						if(style == 'osm'||style == 'osm-no-labels')
						{
							var tile = RegExp.$3;
							var inst = RegExp.$1;
							this.src = 'http://'+inst+'.tile.openstreetmap.org/'+tile;;
							
							if(console && console.log)
								console.log('redirecting request for '+tile+' to openstreetmap.org: '+this.src);
							
							return;
						}
						if(style == 'osm-labels-ru')
						{
                                                this.src = 'http://toolserver.org/~osm/libs/openlayers/latest/img/blank.gif';
                                                return;
						}

						this.src = 'http://www.openstreetmap.org/openlayers/img/404.png';
					}
				};

				
				// get the request-parameters
				var args = OpenLayers.Util.getParameters();
				
				// main map object
				map = new OpenLayers.Map ("map", {
					controls: [
						new OpenLayers.Control.Navigation(),
						new OpenLayers.Control.PanZoomBar(),
						new OpenLayers.Control.Attribution(), 
						new OpenLayers.Control.LayerSwitcher(), 
						new OpenLayers.Control.Permalink(),
						new OpenLayers.Control.ScaleLine({geodesic:true})
						//new OpenLayers.Control.KeyboardDefaults()
					],
					
					// mercator bounds
					maxExtent: new OpenLayers.Bounds(-20037508.34,-20037508.34,20037508.34,20037508.34),
					maxResolution: 156543.0399,
					
					numZoomLevels: 19,
					units: 'm',
					projection: new OpenLayers.Projection("EPSG:900913"),
					displayProjection: new OpenLayers.Projection("EPSG:4326")
				});
				
				// create the custom layer
				OpenLayers.Layer.OSM.Toolserver = OpenLayers.Class(OpenLayers.Layer.OSM, {
					
					initialize: function(name, options) {
						var url = [
							"http://a.www.toolserver.org/tiles/" + name + "/${z}/${x}/${y}.png", 
							"http://b.www.toolserver.org/tiles/" + name + "/${z}/${x}/${y}.png", 
							"http://c.www.toolserver.org/tiles/" + name + "/${z}/${x}/${y}.png"
						];
						
						options = OpenLayers.Util.extend({numZoomLevels: 19}, options);
						OpenLayers.Layer.OSM.prototype.initialize.apply(this, [name, url, options]);
					},
					
					CLASS_NAME: "OpenLayers.Layer.OSM.Toolserver"
				});

					
				// add the osm.org layers
				var osm = new OpenLayers.Layer.OSM.Toolserver('osm', {
				attribution:'<?php echo translate('map-by',$uselang);?> <a target="_blank" href="http://www.openstreetmap.org/"><?php echo translate('openstreetmap',$uselang);?></a> (<a target="_blank" href="http://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>)'})
				osm.setIsBaseLayer(true);
                                map.addLayer(osm);
                                var osmNoLabels = new OpenLayers.Layer.OSM.Toolserver('osm-no-labels',{
				attribution:'<?php echo translate('map-by',$uselang);?> <a target="_blank" href="http://www.openstreetmap.org/"><?php echo translate('openstreetmap',$uselang);?></a> (<a target="_blank" href="http://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>)',
                                visibility: false } ,
                                {isBaseLayer:true                  });
				osmNoLabels.setIsBaseLayer(true);
				osmNoLabels.setVisibility(false);
                                map.addLayer(osmNoLabels);

				map.addLayer(new OpenLayers.Layer.OSM.Mapnik("osm.org mapnik"), {visibility: false});
				
				map.addLayer(new OpenLayers.Layer.OSM.Toolserver('hikebike'));
				
				<?php if($lang=="de") {echo "map.addLayer(new OpenLayers.Layer.OSM.Toolserver('germany'));";}?>
              
               
		 osmLabelsLang = new OpenLayers.Layer.OSM.Toolserver('osm-labels-<?php echo $lang;?>', {isBaseLayer: false, visibility: false, attribution:''});
		map.addLayers([osmLabelsLang]);


     // Wikipedia-World Layer			
    var bboxStrategy = new OpenLayers.Strategy.BBOX( {
        ratio : 1.1,
        resFactor: 1
    });	


    poiLayerHttp = new OpenLayers.Protocol.HTTP({
        url: "http://toolserver.org/~kolossos/geoworld/marks.php?",
        params: { 'LANG' : <?php echo "'".$lang."'".$thumbsinsert.$popinsert.$styleinsert.$photoinsert.$sourceinsert.$notsourceinsert.$secureinsert;?>},
        format: new OpenLayers.Format.KML({
            extractStyles: true, 
            extractAttributes: true
        })             
    });     
    pois = new OpenLayers.Layer.Vector("Wikipedia World", {
        attribution:'<a target="_blank" href="http://de.wikipedia.org/wiki/Wikipedia:WikiProjekt_Georeferenzierung/Wikipedia-World/en"><?php echo translate('wikipedia',$uselang);?></a> (CC-BY-SA)',
        projection: new OpenLayers.Projection("EPSG:4326"),
        strategies: [bboxStrategy],
        protocol: poiLayerHttp
    });
    
    map.addLayer(pois);


<?php 
if ($title){
if ($action=='purge') {$actionurl="&action=purge";}
$vecfile='"'."http://toolserver.org/~master/osmjson/getGeoJSON.php?lang=$lang&article=".rawurlencode($title).$actionurl.'"';

print <<<END
    //OSM objects Layer : Object with the Wikipedia-Tag matching with article-name

    var styleMap = new OpenLayers.StyleMap({'pointRadius': 7,
					    'strokeWidth': 3,
					    'strokeColor': '#ff0000',
					    'fillColor': '#ff0000',
					    'fillOpacity': .3
                         });

    var vector_layer = new OpenLayers.Layer.Vector("OSM objects (loading...)",{
				styleMap: styleMap,
				attribution:' <a target="_blank" href="http://wiki.openstreetmap.org/wiki/WIWOSM">WIWOSM</a> '
       
									    });
    map.addLayer(vector_layer);

    var JSONurl = $vecfile;
    //alert ("$lang $title");
    var p = new OpenLayers.Format.GeoJSON();

    OpenLayers.loadURL(JSONurl, {}, null, function (response) {
    var gformat = new OpenLayers.Format.GeoJSON();
    gg = '{"type":"FeatureCollection", "features":[{"geometry": ' +
	  response.responseText + '}]}';
    var feats = gformat.read(gg);

    vector_layer.addFeatures(feats);
    vector_layer.setName("OSM objects (WIWOSM)");
    document.title = args.title+" on OpenStreetMap";
      if (vector_layer.getDataExtent().getHeight()>0) 
	{ map.zoomToExtent (vector_layer.getDataExtent(),false);} 
     
     if (!args.lon && vector_layer.getDataExtent().getHeight()==0) 
	{map.setCenter (vector_layer.getDataExtent().getCenterLonLat(),17);}  

    }, 
    function (noresponse) {
     vector_layer.setVisibility(false);
     vector_layer.setName("OSM objects (not found)");
     }
    );
END;
}
?>
  

    var feature = null;
    var highlightFeature = null;
    var tooltipTimeout = false;
    var lastFeature = null;
    var selectPopup = null;
    var tooltipPopup = null;
    
    var selectCtrl = new OpenLayers.Control.SelectFeature(pois, {
        toggle:true, 
  	    clickout: true
  	});
    pois.events.on({ "featureselected": onMarkerSelect, "featureunselected": onMarkerUnselect});
    
      map.events.register("zoomend", map, zoomEnd);

    function onMarkerSelect  (evt) {
        eventTooltipOff(evt);
        if(selectPopup != null) {
            map.removePopup(selectPopup);
            selectPopup.feature=null;
            if(feature != null && feature.popup != null){
                feature.popup = null;
            }
        }    
        feature = evt.feature;
        //console.log("feature selected", feature) ;
        //console.log("features in layer", pois.features.length);
        selectPopup = new OpenLayers.Popup.AnchoredBubble("activepopup",
                feature.geometry.getBounds().getCenterLonLat(),
                new OpenLayers.Size(220,170),
                text='<b>'+feature.attributes.name +'</b><br>'+ feature.attributes.description, 
                null, true, onMarkerPopupClose );
    	
        selectPopup.closeOnMove = false;
        selectPopup.autoSize = false;    	
    	feature.popup = selectPopup;
    	selectPopup.feature = feature;     	
    	map.addPopup(selectPopup);
    }

    function onMarkerUnselect  (evt) {
    	feature = evt.feature;
        if(feature != null && feature.popup != null){
            selectPopup.feature = null;            
            map.removePopup(feature.popup);
            feature.popup = null;
        } 
    }
    

    function onMarkerPopupClose(evt) {
        if(selectPopup != null) {
            map.removePopup(selectPopup);
            selectPopup.feature = null;            
            if(feature != null && feature.popup != null) {
                feature.popup = null;
            }    
        }    
        selectCtrl.unselectAll();
    }


    var highlightCtrl = new OpenLayers.Control.SelectFeature(pois, {
        hover: true,
        highlightOnly: true,
        renderIntent: "temporary",
        eventListeners: {
            featurehighlighted: eventTooltipOn,
            featureunhighlighted: eventTooltipOff
        }
    });

    function eventTooltipOn  (evt) {
        highlightFeature = evt.feature;           
        if(tooltipPopup != null) {
            map.removePopup(tooltipPopup);
            tooltipPopup.feature=null;
            if(lastFeature != null) {
                lastFeature.popup = null;                                
            }    
        }    
        lastFeature = highlightFeature;
             
      	//document.getElementById("map_OpenLayers_Container").style.cursor = "pointer";
      	
         tooltipPopup = new OpenLayers.Popup("activetooltip",
                highlightFeature.geometry.getBounds().getCenterLonLat(),
                new OpenLayers.Size(220,20),
                highlightFeature.attributes.name, null, false, null );
    	if(tooltipTimeout) clearTimeout(tooltipTimeout);
    	tooltipPopup.closeOnMove = true;
    	tooltipPopup.autoSize = true;    	
    	map.addPopup(tooltipPopup);    	
    }

    function eventTooltipOff  (evt) {
        highlightFeature = evt.feature;            
        //document.getElementById("map_OpenLayers_Container").style.cursor = "default"; 
	
	if(tooltipPopup)
        {
            tooltipTimeout = setTimeout(function() {
                map.removePopup(tooltipPopup);
                tooltipPopup = null;
            }, 500);
        }

     	
        if(highlightFeature != null && highlightFeature.popup != null){
            map.removePopup(highlightFeature.popup);
            highlightFeature.popup = null;
            tooltipPopup = null;
            lastFeature = null;            
        } 
  	}

     function zoomEnd() {
      var scale = map.getScale();
      //alert (lang);
      if (scale>10000000) {
          $(".olControlScaleLine").css('display', 'none');
      } else {
          $(".olControlScaleLine").css('display', 'block');
      } 

      // below zoom 6 we switch from layer "osm" to layer "osm-no-labels" + "osm-labels-de"
      if((map.getZoom() <= 6 || forcelocal) && map.baseLayer.id == osm.id)
      { 
          map.setBaseLayer(osmNoLabels);
          osmLabelsLang.setVisibility(true);
      }
      if(map.getZoom() <= 6 || forcelocal)
      {
	  osmLabelsLang.displayInLayerSwitcher=true;
	  
	  osmNoLabels.displayInLayerSwitcher=true;
      }
      
      // above zoom 6 we switch back to the usual osm layer
      else if(map.getZoom() > 6 && map.baseLayer.id == osmNoLabels.id && !forcelocal)
      {   
          map.setBaseLayer(osm);
          osmLabelsLang.setVisibility(false);
      }
      if(map.getZoom() > 6  && !forcelocal)
      {
	  osmLabelsLang.displayInLayerSwitcher=false;
	  
	  osmNoLabels.displayInLayerSwitcher=false;
      }

  }

    
    map.addControl(highlightCtrl);
    map.addControl(selectCtrl);
    highlightCtrl.activate();
    selectCtrl.activate();    
    zoomEnd();








				// default zoon
				
				
			      <?php echo $position;?>

				// lat/lon requestes
				if(args.lon && args.lat)
				{
					// zoom requested
					if(args.zoom)
					{
						zoom = parseInt(args.zoom);
						var maxZoom = map.getNumZoomLevels();
						if (zoom >= maxZoom) zoom = maxZoom - 1;
					}
					
					// transform center
					var center = new OpenLayers.LonLat(parseFloat(args.lon), parseFloat(args.lat)).
						transform(map.displayProjection, map.getProjectionObject())
					
					// move to
					map.setCenter(center, zoom);
				}
				
				// bbox requestet
				else if (args.bbox)
				{
					// transform bbox
					var bounds = OpenLayers.Bounds.fromArray(args.bbox).
						transform(map.displayProjection, map.getProjectionObject());
					
					// move to
					map.zoomToExtent(bounds)
				}
				
				// default center
				else
				{
					// set the default center
					var center = new OpenLayers.LonLat(0, 0).
						transform(map.displayProjection, map.getProjectionObject());
					
					// move to
					map.setCenter(center, zoom);
				}
     var markers = new OpenLayers.Layer.Markers( "Marker", {
        attribution:' <a target="_blank" href="http://<?php echo $lang;?>.wikipedia.org/wiki/Help:OpenStreetMap"> <?php echo translate('help',$uselang);?> </a> ' } );
            map.addLayer(markers);

            var size = new OpenLayers.Size(16,16);
            var offset = new OpenLayers.Pixel(-(size.w/2), -(size.h/2));
            var icon = new OpenLayers.Icon('Ol_icon_red_example.png',size,offset);
            markers.addMarker(new OpenLayers.Marker(new OpenLayers.LonLat(map.center.lon,map.center.lat),icon));


 				map.addLayer(new OpenLayers.Layer.OSM(
					'hillshading', 
					'http://toolserver.org/~cmarqu/hill/${z}/${x}/${y}.png',
					{
						displayOutsideMaxExtent: true, 
						isBaseLayer: false,
						transparent: true, 
						visibility: false, 
						numZoomLevels: 16
					}
					));

			}







  function hideInsetMenu() {
                $('#mapInsetMenuDropdown').css('visibility', 'hidden');
            }
            function showInsetMenu() {
                $('#mapInsetMenuDropdown').css('visibility', 'visible');
            }

        

	    

$.ajax({
   type: "GET",
   url: "lang-select.php",
   data: "lang=<?php echo $uselang;?>",
   success: function(msg){
       $('#mapInsetMenuDropdown').empty();
       $(msg).appendTo('#mapInsetMenuDropdown');
	$(function() {
                $('.menuSelect').change(function() {
                    if ($(this).attr('checked')==true) {
                        poiLayerHttp.params[$(this).val()] = 'yes';
                    } else {
                        poiLayerHttp.params[$(this).val()] = 'no';
                    }
                    pois.redraw(true);
                  });    
            });
   
	$(function() {
                $('.menuSelectlist').change(function() {
     
                   poiLayerHttp.params.LANG = $(this).val();
		    if ($(this).val()!='') {
		   osmLabelsLang.name="osm-labels-"+$(this).val();
		   osmLabelsLang.url = [
							"http://a.www.toolserver.org/tiles/" + osmLabelsLang.name + "/${z}/${x}/${y}.png", 
							"http://b.www.toolserver.org/tiles/" + osmLabelsLang.name + "/${z}/${x}/${y}.png", 
							"http://c.www.toolserver.org/tiles/" + osmLabelsLang.name + "/${z}/${x}/${y}.png"
						];
		   osmLabelsLang.redraw(true);
		    }
                   pois.redraw(true);
                  });    
            });


   }
 });

		</script>

<style type="text/css">
			body {
				padding: 0;
				margin: 0;
			}
			
			.olControlAttribution
			{
				bottom: 5px !important;
				right: 80px !important;
			}
			
			.olControlPermalink {
				bottom: 5px !important;
				right: 5px !important;
				width: 60px;
				text-align: center;
			}
			
			.olControlAttribution, .olControlPermalink {
				background-color: white;
				border-color: black;
				border-style: solid;
				border-width: 1px;
				cursor: pointer;
				padding: 2px 4px;
				
				opacity: 0.5;
			}
			
			.olPopupContent, .olControlAttribution, .olControlPermalink {
				font-family: arial, sans-serif;
				font-size: 12px;
			}
			
			.olControlAttribution:hover, .olControlPermalink:hover {
				opacity: 1.0;
			}
			
			.olPopupContent a, .olControlAttribution a, .olControlPermalink a {
				color: #0645AD;
				text-decoration: none;
			}
			
			.olPopupContent a:hover, .olControlAttribution a:hover, .olControlPermalink a:hover {
				text-decoration: underline;
			}
			
			#activetooltip {
				background-color: #ffffcb !important;
				overflow: hidden;
				
				border: 1px solid #DBDBD3 !important;
				
				font-family: arial, sans-serif;
				font-size: 12px;
				height: 8px;
				text-align: center;
			}
			
			#activetooltip .olPopupContent {
				padding: 5px 0 0 0 !important;
			}
			
			.olPopupContent {
				
			}

			.mapBtnOuter {
			border: 1px solid #444;
			background-color: #fff;
			z-index: 2000;
			}
			.mapBtnInner {
			cursor: pointer;
			font-size: 12px;
			font-family: arial, sans-serif;
			border-color:white #bbb #bbb white;
			border-style:solid;
			border-width:1px;
			padding: 2px 4px 2px 4px ;
			}
			#mapInsetMenu {
			position: absolute;
			left: 50px;
			top: 7px;
			}

			div.olLayerDiv {
			  -khtml-user-select: none;
			}

			.olControlScaleLineBottom {
			  display: none;
			}

			#mapInsetMenuDropdown { 
			visibility: hidden;
			padding: 2px 4px 2px 4px ;
			font-size: 12px;
			font-family: arial, sans-serif;
			background-color: #fff;
			border-color: #444;
			border-style:solid;
			border-width:1px;
			position: absolute;
			left: -1px;
			top: 20px;
			width: 250px;
			box-shadow: 2px 2px 2px #666;
			-moz-box-shadow: 2px 2px 2px #666;
			}


		</style>

	</head>
	 
	<body onload="init();">

<div id="mapContainer"  style="width: 100%; height: 100%">   
    <div style="width: 100%; height: 100%" id="map">

    </div>
    
    <div id="mapInsetMenu" class="mapBtnOuter" onmouseout="javascript:hideInsetMenu()" onmouseover="javascript:showInsetMenu()">
        <div id="mapInsetMenuI" class="mapBtnInner">
            <?php echo translate('options',$uselang);?>
        </div>
        <div id="mapInsetMenuDropdown">        
             
		
		<p>
		<b><?php echo translate('thumbnails',$uselang);?></b><br />
		<input class="menuSelect" type="checkbox" name="thumbs" value="thumbs"/>
             <br>
<b>
<?php echo translate('languages',$uselang);?>
</b><br />
<select class="menuSelectlist" name="top5" size="5"> 
<option value=""> ALL  </option>
<option value="de">Deutsch</option>
<option value="en">English</option>
</select>
        </div>

    </div>
     
</div>

	</body>
</html> 
