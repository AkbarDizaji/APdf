<?php


class TCPDF2DBarcode {


	protected $barcode_array = false;


	public function __construct($code, $type) {
		$this->setBarcode($code, $type);
	}


	public function getBarcodeArray() {
		return $this->barcode_array;
	}


	public function getBarcodeSVG($w=3, $h=3, $color='black') {
		// send headers
		$code = $this->getBarcodeSVGcode($w, $h, $color);
		header('Content-Type: application/svg+xml');
		header('Cache-Control: public, must-revalidate, max-age=0'); // HTTP/1.1
		header('Pragma: public');
		header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past
		header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
		header('Content-Disposition: inline; filename="'.md5($code).'.svg";');
		//header('Content-Length: '.strlen($code));
		echo $code;
	}


	public function getBarcodeSVGcode($w=3, $h=3, $color='black') {
		// replace table for special characters
		$repstr = array("\0" => '', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
		$svg = '<'.'?'.'xml version="1.0" standalone="no"'.'?'.'>'."\n";
		$svg .= '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'."\n";
		$svg .= '<svg width="'.round(($this->barcode_array['num_cols'] * $w), 3).'" height="'.round(($this->barcode_array['num_rows'] * $h), 3).'" version="1.1" xmlns="http://www.w3.org/2000/svg">'."\n";
		$svg .= "\t".'<desc>'.strtr($this->barcode_array['code'], $repstr).'</desc>'."\n";
		$svg .= "\t".'<g id="elements" fill="'.$color.'" stroke="none">'."\n";
		// print barcode elements
		$y = 0;
		// for each row
		for ($r = 0; $r < $this->barcode_array['num_rows']; ++$r) {
			$x = 0;
			// for each column
			for ($c = 0; $c < $this->barcode_array['num_cols']; ++$c) {
				if ($this->barcode_array['bcode'][$r][$c] == 1) {
					// draw a single barcode cell
					$svg .= "\t\t".'<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" />'."\n";
				}
				$x += $w;
			}
			$y += $h;
		}
		$svg .= "\t".'</g>'."\n";
		$svg .= '</svg>'."\n";
		return $svg;
	}


	public function getBarcodeHTML($w=10, $h=10, $color='black') {
		$html = '<div style="font-size:0;position:relative;width:'.($w * $this->barcode_array['num_cols']).'px;height:'.($h * $this->barcode_array['num_rows']).'px;">'."\n";
		// print barcode elements
		$y = 0;
		// for each row
		for ($r = 0; $r < $this->barcode_array['num_rows']; ++$r) {
			$x = 0;
			// for each column
			for ($c = 0; $c < $this->barcode_array['num_cols']; ++$c) {
				if ($this->barcode_array['bcode'][$r][$c] == 1) {
					// draw a single barcode cell
					$html .= '<div style="background-color:'.$color.';width:'.$w.'px;height:'.$h.'px;position:absolute;left:'.$x.'px;top:'.$y.'px;">&nbsp;</div>'."\n";
				}
				$x += $w;
			}
			$y += $h;
		}
		$html .= '</div>'."\n";
		return $html;
	}


	public function getBarcodePNG($w=3, $h=3, $color=array(0,0,0)) {
		$data = $this->getBarcodePngData($w, $h, $color);
		// send headers
		header('Content-Type: image/png');
		header('Cache-Control: public, must-revalidate, max-age=0'); // HTTP/1.1
		header('Pragma: public');
		header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past
		header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
		//header('Content-Length: '.strlen($data));
		echo $data;

	}


	public function getBarcodePngData($w=3, $h=3, $color=array(0,0,0)) {
		// calculate image size
		$width = ($this->barcode_array['num_cols'] * $w);
		$height = ($this->barcode_array['num_rows'] * $h);
		if (function_exists('imagecreate')) {
			// GD library
			$imagick = false;
			$png = imagecreate($width, $height);
			$bgcol = imagecolorallocate($png, 255, 255, 255);
			imagecolortransparent($png, $bgcol);
			$fgcol = imagecolorallocate($png, $color[0], $color[1], $color[2]);
		} elseif (extension_loaded('imagick')) {
			$imagick = true;
			$bgcol = new imagickpixel('rgb(255,255,255');
			$fgcol = new imagickpixel('rgb('.$color[0].','.$color[1].','.$color[2].')');
			$png = new Imagick();
			$png->newImage($width, $height, 'none', 'png');
			$bar = new imagickdraw();
			$bar->setfillcolor($fgcol);
		} else {
			return false;
		}
		// print barcode elements
		$y = 0;
		// for each row
		for ($r = 0; $r < $this->barcode_array['num_rows']; ++$r) {
			$x = 0;
			// for each column
			for ($c = 0; $c < $this->barcode_array['num_cols']; ++$c) {
				if ($this->barcode_array['bcode'][$r][$c] == 1) {
					// draw a single barcode cell
					if ($imagick) {
						$bar->rectangle($x, $y, ($x + $w - 1), ($y + $h - 1));
					} else {
						imagefilledrectangle($png, $x, $y, ($x + $w - 1), ($y + $h - 1), $fgcol);
					}
				}
				$x += $w;
			}
			$y += $h;
		}
		if ($imagick) {
			$png->drawimage($bar);
			return $png;
		} else {
			ob_start();
			imagepng($png);
			$imagedata = ob_get_clean();
			imagedestroy($png);
			return $imagedata;
		}
	}


	public function setBarcode($code, $type) {
		$mode = explode(',', $type);
		$qrtype = strtoupper($mode[0]);
		switch ($qrtype) {
			case 'DATAMATRIX': { // DATAMATRIX (ISO/IEC 16022)
				require_once(dirname(__FILE__) . '/includes/barcodes/datamatrix.php');
				$qrcode = new Datamatrix($code);
				$this->barcode_array = $qrcode->getBarcodeArray();
				$this->barcode_array['code'] = $code;
				break;
			}
			case 'PDF417': { // PDF417 (ISO/IEC 15438:2006)
				require_once(dirname(__FILE__) . '/includes/barcodes/pdf417.php');
				if (!isset($mode[1]) OR ($mode[1] === '')) {
					$aspectratio = 2; // default aspect ratio (width / height)
				} else {
					$aspectratio = floatval($mode[1]);
				}
				if (!isset($mode[2]) OR ($mode[2] === '')) {
					$ecl = -1; // default error correction level (auto)
				} else {
					$ecl = intval($mode[2]);
				}
				// set macro block
				$macro = array();
				if (isset($mode[3]) AND ($mode[3] !== '') AND isset($mode[4]) AND ($mode[4] !== '') AND isset($mode[5]) AND ($mode[5] !== '')) {
					$macro['segment_total'] = intval($mode[3]);
					$macro['segment_index'] = intval($mode[4]);
					$macro['file_id'] = strtr($mode[5], "\xff", ',');
					for ($i = 0; $i < 7; ++$i) {
						$o = $i + 6;
						if (isset($mode[$o]) AND ($mode[$o] !== '')) {
							// add option
							$macro['option_'.$i] = strtr($mode[$o], "\xff", ',');
						}
					}
				}
				$qrcode = new PDF417($code, $ecl, $aspectratio, $macro);
				$this->barcode_array = $qrcode->getBarcodeArray();
				$this->barcode_array['code'] = $code;
				break;
			}
			case 'QRCODE': { // QR-CODE
				require_once(dirname(__FILE__) . '/includes/barcodes/qrcode.php');
				if (!isset($mode[1]) OR (!in_array($mode[1],array('L','M','Q','H')))) {
					$mode[1] = 'L'; // Ddefault: Low error correction
				}
				$qrcode = new QRcode($code, strtoupper($mode[1]));
				$this->barcode_array = $qrcode->getBarcodeArray();
				$this->barcode_array['code'] = $code;
				break;
			}
			case 'RAW':
			case 'RAW2': { // RAW MODE
				// remove spaces
				$code = preg_replace('/[\s]*/si', '', $code);
				if (strlen($code) < 3) {
					break;
				}
				if ($qrtype == 'RAW') {
					// comma-separated rows
					$rows = explode(',', $code);
				} else { // RAW2
					// rows enclosed in square parentheses
					$code = substr($code, 1, -1);
					$rows = explode('][', $code);
				}
				$this->barcode_array['num_rows'] = count($rows);
				$this->barcode_array['num_cols'] = strlen($rows[0]);
				$this->barcode_array['bcode'] = array();
				foreach ($rows as $r) {
					$this->barcode_array['bcode'][] = str_split($r, 1);
				}
				$this->barcode_array['code'] = $code;
				break;
			}
			case 'TEST': { // TEST MODE
				$this->barcode_array['num_rows'] = 5;
				$this->barcode_array['num_cols'] = 15;
				$this->barcode_array['bcode'] = array(
					array(1,1,1,0,1,1,1,0,1,1,1,0,1,1,1),
					array(0,1,0,0,1,0,0,0,1,0,0,0,0,1,0),
					array(0,1,0,0,1,1,0,0,1,1,1,0,0,1,0),
					array(0,1,0,0,1,0,0,0,0,0,1,0,0,1,0),
					array(0,1,0,0,1,1,1,0,1,1,1,0,0,1,0));
				$this->barcode_array['code'] = $code;
				break;
			}
			default: {
				$this->barcode_array = false;
			}
		}
	}
} // end of class

//============================================================+
// END OF FILE
//============================================================+
