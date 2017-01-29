<?PHP


/**
 *   Parse geo parameters
 */
class geo_param {
	var $latdeg;
	var $londeg;

	var $latdeg_min;
	var $londeg_min;
	var $latdeg_max;
	var $londeg_max;

	var $pieces;
	var $error;
	var $coor;
	var $title;

	/**
	 *   Constructor:
	 *   Read coordinates, and if there is a range, read the range
	 */
	function geo_param( $param )
	{
		$this->pieces = explode(" ", str_replace ( ' O' , ' E' , str_replace( '_', ' ', $param )));
		$this->get_coor( );
		
		$this->latdeg_min = $this->latdeg_max = $this->latdeg;
		$this->londeg_min = $this->londeg_max = $this->londeg;
		if ( isset( $this->pieces[0] ) && $this->pieces[0] == "to") {
			array_shift($this->pieces);
			$this->get_coor();
			if ($this->latdeg < $this->latdeg_max) {
				$this->latdeg_min = $this->latdeg;
			} else {
				$this->latdeg_max = $this->latdeg;
			}
			if ($this->londeg < $this->londeg_max) {
				$this->londeg_min = $this->londeg;
			} else {
				$this->londeg_max = $this->londeg;
			}
			$this->latdeg = ($this->latdeg_max+$this->latdeg_min) / 2;
			$this->londeg = ($this->londeg_max+$this->londeg_min) / 2;
			$this->coor = array();
		}
	}

	/**
	 *  Private:
	 *  Get a set of coordinates from parameters
	 */
	function get_coor( ) {
		if ($i = strpos($this->pieces[0],';')) {
			/* two values seperated by a semicolon */
			$this->coor = array(
				$this->latdeg = substr($this->pieces[0],0,$i),
				$this->londeg = substr($this->pieces[0],$i+1));
			array_shift($this->pieces);
			$latNS = 'N';
			$lonEW = 'E';
			$latmin = $lonmin = $latsec = $lonsec = 0;
		} elseif ($this->is_coor($this->pieces[1],$this->pieces[3])) {
			$this->coor = array(
				$this->latdeg = array_shift($this->pieces),
				$latNS        = array_shift($this->pieces),
				$this->londeg = array_shift($this->pieces),
				$lonEW        = array_shift($this->pieces));
			$latmin = $lonmin = $latsec = $lonsec = 0;
		} elseif ($this->is_coor($this->pieces[2],$this->pieces[5])) {
			$this->coor = array(
				$this->latdeg = array_shift($this->pieces),
				$latmin       = array_shift($this->pieces),
				$latNS        = array_shift($this->pieces),
				$this->londeg = array_shift($this->pieces),
				$lonmin       = array_shift($this->pieces),
				$lonEW        = array_shift($this->pieces));
			$latsec = $lonsec = 0;
		} elseif ($this->is_coor($this->pieces[3],$this->pieces[7])) {
			$this->coor = array(
				$this->latdeg = array_shift($this->pieces),
				$latmin       = array_shift($this->pieces),
				$latsec       = array_shift($this->pieces),
				$latNS        = array_shift($this->pieces),
				$this->londeg = array_shift($this->pieces),
				$lonmin       = array_shift($this->pieces),
				$lonsec       = array_shift($this->pieces),
				$lonEW        = array_shift($this->pieces));
		} else {
			# support decimal, signed lat, lon
			$this->error = "Unrecognized format";
			print $this->error ;
		}
		
	
		if ($this->latdeg >  90 or $this->latdeg <  -90
		 or $this->londeg > 180 or $this->londeg < -180
		 or $latmin       >  60 or $latmin       <    0
		 or $lonmin       >  60 or $lonmin       <    0
		 or $latsec       >  60 or $latsec       <    0
		 or $lonsec       >  60 or $lonsec       <    0) {
			$this->error = "Out of range";
		}

		$latfactor = 1.0 ;
		$lonfactor = 1.0 ;
		if (strtoupper($latNS) == "S") {
			$latfactor = -1.0 ;
			#$this->latdeg = -$this->latdeg;
		}

		if (strtoupper($lonEW) == "W") {
			$lonfactor = -1.0 ;
			#$this->londeg = -$this->londeg;
		}

		# Make decimal degree, if not already
		$latmin += $latsec/60.0;
		$lonmin += $lonsec/60.0;
		if ($this->latdeg < 0) {
			$this->latdeg -= $latmin/60.0;
		} else {
			$this->latdeg += $latmin/60.0;
		}
		if ($this->londeg < 0) {
			$this->londeg -= $lonmin/60.0;
		} else {
			$this->londeg += $lonmin/60.0;
		}
		$this->latdeg *= $latfactor ;
		$this->londeg *= $lonfactor ;
	}

	/**
	 *   Given decimal degrees, convert to 
	 *   minutes, seconds and direction
	 */
	function make_minsec( $deg )
	{
		if ( $deg >= 0) {
			$NS = "N";
			$EW = "E";
		} else {
			$NS = "S";
			$EW = "W";
		}
		# Round to a suitable number of digits
		# FIXME: should reflect precision
		$deg = round($deg, 6);
		$min = 60.0 * (abs($deg) - intval(abs($deg))); 
		$min = round($min, 4);
		$sec = 60.0 * ($min - intval($min));
		$sec = round($sec, 2);

		return array(
			'deg'   => $deg,
			'min'   => $min,
			'sec'   => $sec,
			'NS'    => $NS,
			'EW'    => $EW);
	}

	/**
	 *   Given decimal degrees latitude and longitude, convert to
	 *   string
	 */
	function make_position( $lat, $lon )
	{
		$latdms = geo_param::make_minsec( $lat );
		$londms = geo_param::make_minsec( $lon );
		$outlat = intval(abs($latdms['deg'])) . "&deg;&nbsp;";
		$outlon = intval(abs($londms['deg'])) . "&deg;&nbsp;";
		if ($latdms['min'] != 0 or $londms['min'] != 0
		 or $latdms['sec'] != 0 or $londms['sec'] != 0) {
			$outlat .= intval($latdms['min']) . "&prime;&nbsp;";
			$outlon .= intval($londms['min']) . "&prime;&nbsp;";
			if ($latdms['sec'] != 0 or $londms['sec'] != 0) {
				$outlat .= $latdms['sec']. "&Prime;&nbsp;";
				$outlon .= $londms['sec']. "&Prime;&nbsp;";
			}
		}
		return $outlat . $latdms['NS'] . " " . $outlon . $londms['EW'];
	}

	/**
	 *  Get the additional attributes in an associative array
	 */
	function get_attr()
	{
		$a = array();
		while (($s = array_shift($this->pieces))) {
			if (($i = strpos($s,":")) >= 1) {
				$attr = substr($s,0,$i);
				$val = substr($s,$i+1);
				if (($j = strpos($val,"("))
				 && ($k = strpos($val,")"))
				 && ($k > $j)) {
					$a["arg:".$attr] = substr($val,$j+1,$k-($j+1));
					$val = substr($val,0,$j);
				}
				$a[$attr] = $val;
			} elseif (intval($s) > 0) {
			    if ($a['$scale'] != "")
				$a['scale'] = intval($s);
			}
		}
		return $a;
	}

	function is_coor( $ns,$ew )
	{
		$ns = strtoupper($ns);
		$ew = strtoupper($ew);
		return (($ns=="N" or $ns=="S") and
			($ew=="E" or $ew=="W"));
	}

	function frac( $f)
	{
		return abs($f) - abs(intval($f));
	}

	/**
	 *  Get composite position in RFC2045 format
	 */
	function get_position( )
	{
		return $this->latdeg.";".$this->londeg;
	}

	/**
	 *  Get error message that applies, or "" of all is well
	 */
	function get_error()
	{
		if ($this->error != "") {
			return "Error:".$this->error;
		}
		return "";
	}

	/**
	 *  Produce markup suitable for use in page
	 *  Use original content as much as possible
	 */
	function get_markup() 
	{
		$n = count($this->coor);

		if ($n == 0) {
			# Range is special case
			return $this->make_position( $this->latdeg_min, 
						     $this->londeg_min )
			     . " to "
			     . $this->make_position( $this->latdeg_max,
						     $this->londeg_max );
		} elseif ($n == 2) {
			return $this->coor[0].';'.
			       $this->coor[1];

		} elseif ($n == 4) {
			return $this->coor[0].'&deg;&nbsp;'.
			       $this->coor[1].' '.
			       $this->coor[2].'&deg;&nbsp;'.
			       $this->coor[3];

		} elseif ($n == 6) {
			return $this->coor[0].'&deg;'.
			       $this->coor[1].'&prime;&nbsp;'.
			       $this->coor[2].' '.
			       $this->coor[3].'&deg;'.
			       $this->coor[4].'&prime;&nbsp;'.
			       $this->coor[5];

		} elseif ($n == 8) {
			return $this->coor[0].'&deg;'.
			       $this->coor[1].'&prime;'.
			       $this->coor[2].'&Prime;&nbsp;'.
			       $this->coor[3].' '.
			       $this->coor[4].'&deg;'.
			       $this->coor[5].'&prime;'.
			       $this->coor[6].'&Prime;&nbsp;'.
			       $this->coor[7];
		} else {
			return $this->get_error();
		}
	}
}

?>
