<?php
/** \file
 *
 *  Create a page which link to other map resources by adding the facility
 *  to embed coordinates in the URLs of these map resources according to
 *  various rules. See also
 *  http://en.wikipedia.org/wiki/Wikipedia:WikiProject_Geographical_coordinates
 *
 *  The displayed page is based on "Wikipedia:Map sources" (or similar)
 *
 *  \todo Translations
 *
 *  ----------------------------------------------------------------------
 *
 *  Copyright 2005, Egil Kvaleberg <egil@kvaleberg.no>
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

require_once( 'transversemercator.php' );

/**
 *  Base class
 */
class map_sources {
	var $p;
	var $mapsources;
	var $thetext ;

	function map_sources( $coor, $title ) {
		$this->p = new geo_param( $coor, $title );
		$this->p->title = $title;

		$this->mapsources = "Map sources";
		# FIXME: translate via wfMsg( "mapsources" )
	}
/*
	function show() {
		global $wgOut;

		// No reason for robots to follow map links
		$wgOut->setRobotpolicy( 'noindex,nofollow' );

		$wgOut->setPagetitle( $this->mapsources );
		$wgOut->addWikiText( $this->build_output() );
	}
*/	
	function build_output() {
#		global $wgOut, $wgUser, $wgContLang, $wgRequest;
/*
		if (($e = $this->p->get_error()) != "") {
			$wgOut->addHTML(
			       "<p>" . htmlspecialchars( $e ) . "</p>");
			$wgOut->output();
			wfErrorExit();
			return "";
		}
*/
		$attr = $this->p->get_attr();

		#$sk = $wgUser->getSkin();

		#
		#   dim: to scale: convertion
		#
		if ( !isset( $attr['scale'] ) && isset( $attr['dim'] ) ) {
			# dia (m) [ (in per m) * (pixels per in) * screen size ]
			# Assume viewport size is 10 cm by 10 cm
			# FIXME document numbers
			# FIXME better convertion
			$attr['scale'] = str_replace(array("km","m"),array("000",""),$attr['dim']) / 0.1;
		}

		#
		#  Default scale
		#
		if ( !isset( $attr['scale'] ) || $attr['scale'] <= 0) {
			if ( !isset( $attr['default'] ) || $attr['default'] == 0) {
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


				if( isset ( $attr['type'] ) ) {
					$attr['default'] = $default_scale[$attr['type']];
				} else {
					$attr['default'] = 0;
				}

				# FIXME: Scale according to city size, if available
			}
			if ($attr['default'] == 0) {
				/* No type and no default, make an assumption */
				# FIXME: scale to input precision
				if ( count($this->p->coor) == 8 ) { # DMS
					$attr['default'] = 10000; # 10 thousand
				} else if ( count($this->p->coor) == 6 ){ # DM
					$attr['default'] = 100000; # 500 thousand
				} else {
					$attr['default'] = 300000; # 3000 thousand
				}
			}
			$attr['scale'] = $attr['default'];
		}

		/*
		 *  Convert coordinates to various Transverse Mercator forms
		 */

		/* standard UTM */
		$utm = new transversemercator();
		$utm->LatLon2UTM( $this->p->latdeg, $this->p->londeg );
		$utm->Zone = $utm->LatLon2UTMZone( $this->p->latdeg, $this->p->londeg );

		/* fixed UTM as used by iNatur */
		$utm33 = new transversemercator();
		$utm33->LatLonZone2UTM( $this->p->latdeg, $this->p->londeg, "33V" );

		/*  UK National Grid, see http://www.gps.gov.uk/guide7.asp
		 *  central meridian 47N 2W, offset 100km N 400km W */
		$osgb36 = new transversemercator();
		$osgb36ref = $osgb36->LatLon2OSGB36( $this->p->latdeg, $this->p->londeg );

		/* Swiss traditional national grid */
		$ch1903 = new transversemercator();
		$ch1903->LatLon2CH1903( $this->p->latdeg, $this->p->londeg );

		/*
		 *  Mapquest style zoom
		 *  9 is approx 1:6,000
		 *  5 (default) is approx 1:333,000
		 *  2 is approx 1:8,570,000
		 *  0 is minimum
		 */
		if ( isset( $attr['scale'] ) && $attr['scale'] > 0) {
			$zoom = intval(18.0 - log($attr['scale']));
		} else {
			$zoom = 9;
		}
		if ($zoom < 0) $zoom = 0;
		if ($zoom > 9) $zoom = 9;

 		/*
		 *  Openstreetmap style zoom
		 *  18 (max) is 1:1,693
		 *  n-1 is half of n
		 *  2 (min) is about 1:111,000,000
		 */
		if ( isset( $attr['scale'] ) && $attr['scale'] > 0) {
			$osmzoom = 18 - ( round(log($attr['scale'],2) - log(1693,2)) );
		} else {
			$osmzoom = 12;
		}
		if ($osmzoom < 0) $osmzoom = 0;
		if ($osmzoom > 18) $osmzoom = 18;

		/*

		/*
		 *  MSN uses an altitude equivalent
		 *  instead of a scale: 
		 *  143 == 1:1000000 scale
		 */
		$altitude = intval( $attr['scale'] * 143/1000000 );
		if ($altitude < 1) $altitude = 1;

		/*
		 * Tiger and Google uses a span
		 * FIXME calibration
		 * 1.0 for 1:1000000
		 */
		$span = $attr['scale'] * 1.0 / 1000000;

		/*
		 * Multimap has a fixed set of scales
		 * and will choke unless one of them are specified
		 */
		if     ($attr['scale'] >= 30000000) $mmscale = 40000000;
		elseif ($attr['scale'] >= 14000000) $mmscale = 20000000;
		elseif ($attr['scale'] >= 6300000)  $mmscale = 10000000;
		elseif ($attr['scale'] >= 2800000)  $mmscale =  4000000;
		elseif ($attr['scale'] >= 1400000)  $mmscale =  2000000;
		elseif ($attr['scale'] >= 700000)   $mmscale =  1000000;
		elseif ($attr['scale'] >= 310000)   $mmscale =   500000;
		elseif ($attr['scale'] >= 140000)   $mmscale =   200000;
		elseif ($attr['scale'] >=  70000)   $mmscale =   100000;
		elseif ($attr['scale'] >=  35000)   $mmscale =    50000;
		elseif ($attr['scale'] >=  15000)   $mmscale =    25000;
		elseif ($attr['scale'] >=   7000)   $mmscale =    10000;
		else                                $mmscale =     5000;

		/*
		 *  Make minutes and seconds, and round
		 */
		$lat = $this->p->make_minsec($this->p->latdeg);
		$lon = $this->p->make_minsec($this->p->londeg);
#		print "?{$this->p->latdeg}/{$this->p->londeg}?" ;
#		print "!" . implode ( ',' , $lat ) . "/" . implode ( ',' , $long ) . "!" ;

		/*
		 *  Hack for negative, small degrees
		 */
		$latdegint = intval($lat['deg']);
		$londegint = intval($lon['deg']);
		if ($this->p->latdeg < 0 and $latdegint == 0) {
			$latdegint = "-0";
		}
		if ($this->p->londeg < 0 and $londegint == 0) {
			$londegint = "-0";
		}

		$latdeground = round($lat['deg']);
		$londeground = round($lon['deg']);
		if ($this->p->latdeg < 0 and $latdeground == 0) {
			$latdeground = "-0";
		}
		if ($this->p->londeg < 0 and $londeground == 0) {
			$londeground = "-0";
		}

		$latdeg_outer_abs = ceil ( abs ( $lat['deg'] ) ) ;
		$londeg_outer_abs = ceil ( abs ( $lon['deg'] ) ) ;
		

		/*
		 *  Look up page from Wikipedia
		 *  See if we have something in
		 *  [[Wikipedia:Map sources]] or equivalent.
		 *  A subpage can be specified
		 */
		$src = $this->mapsources;
		$region = "";
		if ( isset( $attr['page'] ) && $attr['page'] != "") {
		    $src .= "/" . $attr['page']; # subpage specified
		} elseif ( isset( $attr['globe'] ) && $attr['globe'] != "") {
		    $src .= "/" . $attr['globe']; # subpage specified
		} elseif ( isset( $attr['region'] ) && $attr['region'] != "") {
		    $region = strtoupper(substr($attr['region'],0,2));
		    $region = "/" . $region; # subpage specified
		}
		
		$bstitle = 'Something' ;
		#$bstitle = Title::makeTitleSafe( NS_PROJECT, $src.$region );
		#$bsarticle = new Article( $bstitle );
/*
		if (($region != "")
		 and ($bsarticle->getID() == 0)) {
			// * Region article does not exist, and was a subpage
			 // * Default to main page
			$bstitle = Title::makeTitleSafe( NS_PROJECT, $src );
			$bsarticle = new Article( $bstitle );
		}
*/
/*		if ($bsarticle->getID() == 0) {
			$wgOut->addHTML( "<p>Please add this page: " .
				$sk->makeBrokenLinkObj( $bstitle ).".</p>");
			$wgOut->output();
			wfErrorExit();
			return "";
		}*/
		#$bstext = $bsarticle->getContent( false ); # allow redir
		$bstext = $this->thetext ;

		/*
		 * Replace in page
		 */
		$search = array( 
			"{latdegdec}", "{londegdec}",
			"{latdegdecabs}", "{londegdecabs}",
			"{latdeground}", "{londeground}",
			"{latdegroundabs}", "{londegroundabs}",
			"{latdeg_outer_abs}", "{londeg_outer_abs}",
			"{latantipodes}", "{longantipodes}",
			"{londegneg}", "{latdegint}",
			"{londegint}", "{latdegabs}",
			"{londegabs}", "{latmindec}",
			"{lonmindec}", "{latminint}",
			"{lonminint}", "{latsecdec}",
			"{lonsecdec}", "{latsecint}",
			"{lonsecint}", "{latNS}",
			"{lonEW}", "{utmzone}",
			"{utmnorthing}", "{utmeasting}",
			"{utm33northing}", "{utm33easting}",
			"{osgb36ref}", "{osgb36northing}",
			"{osgb36easting}", "{ch1903northing}",
			"{ch1903easting}", "{scale}",
			"{mmscale}", "{altitude}",
			"{zoom}", "{osmzoom}", "{span}",
			"{type}", "{region}",
			"{globe}", "{page}",
			"{pagename}", "{title}", 
			"{geocountry}", "{geoa1}",
			"{params}", "{language}",
			"{pagename_gmaps}" ) ;
		
		# grab global varibles from geohack.php
		global $r_title, $r_pagename;# FIXME operations back in here
		
		if ($lon['deg'] > 0 ) $longantipodes = $lon['deg']-180;
		else $longantipodes = $lon['deg']+180;

		#solve the Bracket  google maps  bug for  ~para/cgi-bin/kmlexport
		$pagename_gmaps=str_replace(array("(",")"),array("%2528","%2529"),$r_pagename);
		
		$replace = array(
			$lat['deg'],
			$lon['deg'],
			abs($lat['deg']),
			abs($lon['deg']),
			$latdeground,
			$londeground,
			abs($latdeground),
			abs($londeground),
			$latdeg_outer_abs,
			$londeg_outer_abs,
			-$lat['deg'],
			$longantipodes,
			-$lon['deg'],
			$latdegint,
			$londegint,
			abs(intval($lat['deg'])),
			abs(intval($lon['deg'])),
			$lat['min'],
			$lon['min'],
			intval($lat['min']),
			intval($lon['min']),
			$lat['sec'],
			$lon['sec'],
			intval($lat['sec']),
			intval($lon['sec']),
			$lat['NS'],
			$lon['EW'],
			$utm->Zone,
			round($utm->Northing),
			round($utm->Easting),
			round($utm33->Northing),
			round($utm33->Easting),
			$osgb36ref,
			round($osgb36->Northing),
			round($osgb36->Easting),
			round($ch1903->Northing),
			round($ch1903->Easting),
			$attr['scale'],
			$mmscale,
			$altitude,
			$zoom,
			$osmzoom,
			$span,
			isset( $attr['type'] ) ? $attr['type'] : "",
			isset( $attr['region'] ) ? $attr['region'] : "",
			isset( $attr['globe'] ) ? $attr['globe'] : "",
			isset( $attr['page'] ) ? $attr['page'] : "",
			#get_request( 'title', '' )
			$r_pagename,
			$r_title,
			$region,
			isset( $attr['region'] ) ? strtoupper(substr($attr['region'], 4, 8)) : "",
			htmlspecialchars ( get_request ( 'params' ) ),
			htmlspecialchars ( get_request ( 'language', 'en' ) ),
			$pagename_gmaps
		);

		return str_replace( $search, $replace, $bstext );
	}
}

?>
