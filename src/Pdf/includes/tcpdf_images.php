<?php

class TCPDF_IMAGES {


	public static $svginheritprop = array('clip-rule', 'color', 'color-interpolation', 'color-interpolation-filters', 'color-profile', 'color-rendering', 'cursor', 'direction', 'display', 'fill', 'fill-opacity', 'fill-rule', 'font', 'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant', 'font-weight', 'glyph-orientation-horizontal', 'glyph-orientation-vertical', 'image-rendering', 'kerning', 'letter-spacing', 'marker', 'marker-end', 'marker-mid', 'marker-start', 'pointer-events', 'shape-rendering', 'stroke', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'stroke-width', 'text-anchor', 'text-rendering', 'visibility', 'word-spacing', 'writing-mode');

// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -


	public static function getImageFileType($imgfile, $iminfo=array()) {
		$type = '';
		if (isset($iminfo['mime']) AND !empty($iminfo['mime'])) {
			$mime = explode('/', $iminfo['mime']);
			if ((count($mime) > 1) AND ($mime[0] == 'image') AND (!empty($mime[1]))) {
				$type = strtolower(trim($mime[1]));
			}
		}
		if (empty($type)) {
            $type = strtolower(trim(pathinfo(parse_url($imgfile, PHP_URL_PATH), PATHINFO_EXTENSION)));
		}
		if ($type == 'jpg') {
			$type = 'jpeg';
		}
		return $type;
	}


	public static function setGDImageTransparency($new_image, $image) {
		// default transparency color (white)
		$tcol = array('red' => 255, 'green' => 255, 'blue' => 255);
		// transparency index
		$tid = imagecolortransparent($image);
		$palletsize = imagecolorstotal($image);
		if (($tid >= 0) AND ($tid < $palletsize)) {
			// get the colors for the transparency index
			$tcol = imagecolorsforindex($image, $tid);
		}
		$tid = imagecolorallocate($new_image, $tcol['red'], $tcol['green'], $tcol['blue']);
		imagefill($new_image, 0, 0, $tid);
		imagecolortransparent($new_image, $tid);
		return $new_image;
	}


	public static function _toPNG($image, $tempfile) {
		// turn off interlaced mode
		imageinterlace($image, 0);
		// create temporary PNG image
		imagepng($image, $tempfile);
		// remove image from memory
		imagedestroy($image);
		// get PNG image data
		$retvars = self::_parsepng($tempfile);
		// tidy up by removing temporary image
		unlink($tempfile);
		return $retvars;
	}


	public static function _toJPEG($image, $quality, $tempfile) {
		imagejpeg($image, $tempfile, $quality);
		imagedestroy($image);
		$retvars = self::_parsejpeg($tempfile);
		// tidy up by removing temporary image
		unlink($tempfile);
		return $retvars;
	}


	public static function _parsejpeg($file) {
		// check if is a local file
		if (!@tcpdf_static::file_exists($file)) {
			return false;
		}
		$a = getimagesize($file);
		if (empty($a)) {
			//Missing or incorrect image file
			return false;
		}
		if ($a[2] != 2) {
			// Not a JPEG file
			return false;
		}
		// bits per pixel
		$bpc = isset($a['bits']) ? intval($a['bits']) : 8;
		// number of image channels
		if (!isset($a['channels'])) {
			$channels = 3;
		} else {
			$channels = intval($a['channels']);
		}
		// default colour space
		switch ($channels) {
			case 1: {
				$colspace = 'DeviceGray';
				break;
			}
			case 3: {
				$colspace = 'DeviceRGB';
				break;
			}
			case 4: {
				$colspace = 'DeviceCMYK';
				break;
			}
			default: {
				$channels = 3;
				$colspace = 'DeviceRGB';
				break;
			}
		}
		// get file content
		$data = file_get_contents($file);
		// check for embedded ICC profile
		$icc = array();
		$offset = 0;
		while (($pos = strpos($data, "ICC_PROFILE\0", $offset)) !== false) {
			// get ICC sequence length
			$length = (tcpdf_static::_getUSHORT($data, ($pos - 2)) - 16);
			// marker sequence number
			$msn = max(1, ord($data[($pos + 12)]));
			// number of markers (total of APP2 used)
			$nom = max(1, ord($data[($pos + 13)]));
			// get sequence segment
			$icc[($msn - 1)] = substr($data, ($pos + 14), $length);
			// move forward to next sequence
			$offset = ($pos + 14 + $length);
		}
		// order and compact ICC segments
		if (count($icc) > 0) {
			ksort($icc);
			$icc = implode('', $icc);
			if ((ord($icc[36]) != 0x61) OR (ord($icc[37]) != 0x63) OR (ord($icc[38]) != 0x73) OR (ord($icc[39]) != 0x70)) {
				// invalid ICC profile
				$icc = false;
			}
		} else {
			$icc = false;
		}
		return array('w' => $a[0], 'h' => $a[1], 'ch' => $channels, 'icc' => $icc, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'DCTDecode', 'data' => $data);
	}


	public static function _parsepng($file) {
		$f = @fopen($file, 'rb');
		if ($f === false) {
			// Can't open image file
			return false;
		}
		//Check signature
		if (fread($f, 8) != chr(137).'PNG'.chr(13).chr(10).chr(26).chr(10)) {
			// Not a PNG file
			return false;
		}
		//Read header chunk
		fread($f, 4);
		if (fread($f, 4) != 'IHDR') {
			//Incorrect PNG file
			return false;
		}
		$w = tcpdf_static::_freadint($f);
		$h = tcpdf_static::_freadint($f);
		$bpc = ord(fread($f, 1));
		$ct = ord(fread($f, 1));
		if ($ct == 0) {
			$colspace = 'DeviceGray';
		} elseif ($ct == 2) {
			$colspace = 'DeviceRGB';
		} elseif ($ct == 3) {
			$colspace = 'Indexed';
		} else {
			// alpha channel
			fclose($f);
			return 'pngalpha';
		}
		if (ord(fread($f, 1)) != 0) {
			// Unknown compression method
			fclose($f);
			return false;
		}
		if (ord(fread($f, 1)) != 0) {
			// Unknown filter method
			fclose($f);
			return false;
		}
		if (ord(fread($f, 1)) != 0) {
			// Interlacing not supported
			fclose($f);
			return false;
		}
		fread($f, 4);
		$channels = ($ct == 2 ? 3 : 1);
		$parms = '/DecodeParms << /Predictor 15 /Colors '.$channels.' /BitsPerComponent '.$bpc.' /Columns '.$w.' >>';
		//Scan chunks looking for palette, transparency and image data
		$pal = '';
		$trns = '';
		$data = '';
		$icc = false;
		$n = tcpdf_static::_freadint($f);
		do {
			$type = fread($f, 4);
			if ($type == 'PLTE') {
				// read palette
				$pal = tcpdf_static::rfread($f, $n);
				fread($f, 4);
			} elseif ($type == 'tRNS') {
				// read transparency info
				$t = tcpdf_static::rfread($f, $n);
				if ($ct == 0) { // DeviceGray
					$trns = array(ord($t[1]));
				} elseif ($ct == 2) { // DeviceRGB
					$trns = array(ord($t[1]), ord($t[3]), ord($t[5]));
				} else { // Indexed
					if ($n > 0) {
						$trns = array();
						for ($i = 0; $i < $n; ++ $i) {
							$trns[] = ord($t[$i]);
						}
					}
				}
				fread($f, 4);
			} elseif ($type == 'IDAT') {
				// read image data block
				$data .= tcpdf_static::rfread($f, $n);
				fread($f, 4);
			} elseif ($type == 'iCCP') {
				// skip profile name
				$len = 0;
				while ((ord(fread($f, 1)) != 0) AND ($len < 80)) {
					++$len;
				}
				// get compression method
				if (ord(fread($f, 1)) != 0) {
					// Unknown filter method
					fclose($f);
					return false;
				}
				// read ICC Color Profile
				$icc = tcpdf_static::rfread($f, ($n - $len - 2));
				// decompress profile
				$icc = gzuncompress($icc);
				fread($f, 4);
			} elseif ($type == 'IEND') {
				break;
			} else {
				tcpdf_static::rfread($f, $n + 4);
			}
			$n = tcpdf_static::_freadint($f);
		} while ($n);
		if (($colspace == 'Indexed') AND (empty($pal))) {
			// Missing palette
			fclose($f);
			return false;
		}
		fclose($f);
		return array('w' => $w, 'h' => $h, 'ch' => $channels, 'icc' => $icc, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'FlateDecode', 'parms' => $parms, 'pal' => $pal, 'trns' => $trns, 'data' => $data);
	}

} // END OF TCPDF_IMAGES CLASS

//============================================================+
// END OF FILE
//============================================================+
