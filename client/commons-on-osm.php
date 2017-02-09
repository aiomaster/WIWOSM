<html>
    <head>
        <title>Commons on OSM</title>

        <style type="text/css">
            body {
                padding: 0;
                margin: 0;
            }
            .olPopupContent img {border: none;}

            .olControlAttribution{bottom:0 !important;}
        </style>

<?php
require_once ( "geo_param.php" ) ;
$lang=addslashes(urldecode($_GET[lang]));

if ( isset ( $_REQUEST['params'] ) ) {
    $p = new geo_param(  $_REQUEST['params'] , "Dummy" );
    $x = $p->londeg ;
    $y = $p->latdeg ;
    $position= "args.lon = $x; args.lat = $y;";
    echo "<!--- //position:".$position." --->\n";
}

?>
        <script src="//tools.wmflabs.org/osm/libs/jquery/latest/jquery-min.js" type="text/javascript"></script>
        <script src="//tools.wmflabs.org/osm/libs/openlayers/2.12/OpenLayers-min.js" type="text/javascript"></script>

        <script src="//tools.wmflabs.org/osm/libs/openstreetmap/latest/OpenStreetMap.js"></script>

        <script type="text/javascript">
// map object
var map;

// initiator
function init()
{
    // show an error image for missing tiles
    OpenLayers.Util.onImageLoadError = function()
    {
        if (urlRegex.test(this.src))
        {
            var style = RegExp.$2;
            if (style == 'osm')
            {
                var tile = RegExp.$3;
                var inst = RegExp.$1;
                this.src = 'http://'+inst+'.tile.openstreetmap.org/'+tile;;

                if (console && console.log)
                    console.log('redirecting request for '+tile+' to openstreetmap.org: '+this.src);

                return;
            }

            this.src = 'http://www.openstreetmap.org/openlayers/img/404.png';
        }
    };

    // show an error image for missing tiles
    OpenLayers.Util.onImageLoadError=function() {
        this.src = 'http://www.openstreetmap.org/openlayers/img/404.png';
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
            new OpenLayers.Control.Permalink()
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
                "//tiles.wmflabs.org/" + name + "/${z}/${x}/${y}.png"
            ];

            options = OpenLayers.Util.extend({numZoomLevels: 19}, options);
            OpenLayers.Layer.OSM.prototype.initialize.apply(this, [name, url, options]);
        },

        CLASS_NAME: "OpenLayers.Layer.OSM.Toolserver"
    });
    // add the osm from Toolserver layers

    var osm = new OpenLayers.Layer.OSM.Toolserver('osm',{
                  tileOptions: { crossOriginKeyword: null }
              });
    map.addLayer(osm);

    // add the osm.org layers
    map.addLayer(new OpenLayers.Layer.OSM.Mapnik("OSM.org"), {visibility: false});

    var bboxStrategy = new OpenLayers.Strategy.BBOX( {
        ratio : 1.1,
        resFactor: 1
    });

    var pois = new OpenLayers.Layer.Vector("Commons", {
        attribution:'CC-BY-SA by <a href="//commons.wikimedia.org/wiki/Commons:Geocoding">Wikimedia Commons</a>',
        projection: new OpenLayers.Projection("EPSG:4326"),
        strategies: [bboxStrategy],
        protocol:
            new OpenLayers.Protocol.HTTP({
                url: "//tools.wmflabs.org/geocommons/kml",
                /* url: "//toolserver.org/~para/GeoCommons/kml.php?f=photos&simple",*/
                /*url: "http://toolserver.org/~kolossos/geoworld/marks.php?LANG=<?php echo $lang;?>",*/
                /*url: "GeoworldProxy?lang=de",*/
                format: new OpenLayers.Format.KML({
                           extractStyles: true,
                           extractAttributes: true
                })
            })
    });

    map.addLayer(pois);

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

    var feature = null;
    var highlightFeature = null;
    var lastFeature = null;
    var selectPopup = null;
    var tooltipPopup = null;

    var selectCtrl = new OpenLayers.Control.SelectFeature(pois, {
        toggle:true,
        clickout: true
    });
    pois.events.on({ "featureselected": onMarkerSelect, "featureunselected": onMarkerUnselect});

    function onMarkerSelect  (evt) {
        eventTooltipOff(evt);
        if (selectPopup != null) {
            map.removePopup(selectPopup);
            selectPopup.feature=null;
            if (feature != null && feature.popup != null) {
                feature.popup = null;
            }
        }
        feature = evt.feature;
        //console.log("feature selected", feature) ;
        //console.log("features in layer", pois.features.length);
        selectPopup = new OpenLayers.Popup.AnchoredBubble("activepopup",
                feature.geometry.getBounds().getCenterLonLat(),
                new OpenLayers.Size(320,320),
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
        if (feature != null && feature.popup != null) {
            selectPopup.feature = null;
            map.removePopup(feature.popup);
            feature.popup = null;
        }
    }

    function onMarkerPopupClose(evt) {
        if (selectPopup != null) {
            map.removePopup(selectPopup);
            selectPopup.feature = null;
            if (feature != null && feature.popup != null) {
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
        if (tooltipPopup != null) {
            map.removePopup(tooltipPopup);
            tooltipPopup.feature=null;
            if (lastFeature != null) {
                lastFeature.popup = null;
            }
        }
        lastFeature = highlightFeature;

        //document.getElementById("map_OpenLayers_Container").style.cursor = "pointer";

        tooltipPopup = new OpenLayers.Popup("activetooltip",
                highlightFeature.geometry.getBounds().getCenterLonLat(),
                new OpenLayers.Size(200,200),
                "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;"+highlightFeature.attributes.description.replace(/<[hH][1-3][^>]*>(.*?)<\/[hH][1-3]>/,""), null, false, null );
	    tooltipPopup.contentDiv.style.backgroundColor='ffffff';
    	tooltipPopup.contentDiv.style.overflow='hidden';
    	tooltipPopup.contentDiv.style.padding='6px';
    	tooltipPopup.contentDiv.style.margin='0px';
    	tooltipPopup.border = '3px solid #DBDBD3';
    	tooltipPopup.closeOnMove = true;
    	tooltipPopup.autoSize = true;    	
    	highlightFeature.popup = tooltipPopup;
    	map.addPopup(tooltipPopup);    	
    }

    function eventTooltipOff  (evt) {
        highlightFeature = evt.feature;
        //document.getElementById("map_OpenLayers_Container").style.cursor = "default";
        if (highlightFeature != null && highlightFeature.popup != null) {
            map.removePopup(highlightFeature.popup);
            highlightFeature.popup = null;
            tooltipPopup = null;
            lastFeature = null;
        }
    }

    map.addControl(highlightCtrl);
    map.addControl(selectCtrl);
    highlightCtrl.activate();
    selectCtrl.activate();

    // default zoon
    var zoom = 12;

    <?php echo $position;?>

    // lat/lon requestes
    if (args.lon && args.lat)
    {
        // zoom requested
        if (args.zoom)
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

    var markers = new OpenLayers.Layer.Markers( "Markers" );
    map.addLayer(markers);

    var size = new OpenLayers.Size(16,16);
    var offset = new OpenLayers.Pixel(-(size.w/2), -(size.h/2));
    var icon = new OpenLayers.Icon('Ol_icon_red_example.png',size,offset);
    markers.addMarker(new OpenLayers.Marker(new OpenLayers.LonLat(map.center.lon,map.center.lat),icon));
}
        </script>
    </head>

    <body onload="init();" id="map">
    </body>
</html>
