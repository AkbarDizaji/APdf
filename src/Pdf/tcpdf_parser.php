<?php




































require_once(dirname(__FILE__) . '/includes/tcpdf_filters.php');


class TCPDF_PARSER {


	private $pdfdata = '';


	protected $xref = array();


	protected $objects = array();


	private $FilterDecoders;


	private $cfg = array(
		'die_for_errors' => false,
		'ignore_filter_decoding_errors' => true,
		'ignore_missing_filter_decoders' => true,
	);




	public function __construct($data, $cfg=array()) {
		if (empty($data)) {
			$this->Error('Empty PDF data.');
		}

		if (($trimpos = strpos($data, '%PDF-')) === FALSE) {
			$this->Error('Invalid PDF data: missing %PDF header.');
		}

		$this->pdfdata = substr($data, $trimpos);

		$pdflen = strlen($this->pdfdata);

		$this->setConfig($cfg);

		$this->xref = $this->getXrefData();

		$this->objects = array();
		foreach ($this->xref['xref'] as $obj => $offset) {
			if (!isset($this->objects[$obj]) AND ($offset > 0)) {

				$this->objects[$obj] = $this->getIndirectObject($obj, $offset, true);
			}
		}

		unset($this->pdfdata);
		$this->pdfdata = '';
	}


	protected function setConfig($cfg) {
		if (isset($cfg['die_for_errors'])) {
			$this->cfg['die_for_errors'] = !!$cfg['die_for_errors'];
		}
		if (isset($cfg['ignore_filter_decoding_errors'])) {
			$this->cfg['ignore_filter_decoding_errors'] = !!$cfg['ignore_filter_decoding_errors'];
		}
		if (isset($cfg['ignore_missing_filter_decoders'])) {
			$this->cfg['ignore_missing_filter_decoders'] = !!$cfg['ignore_missing_filter_decoders'];
		}
	}


	public function getParsedData() {
		return array($this->xref, $this->objects);
	}


	protected function getXrefData($offset=0, $xref=array()) {
		if ($offset == 0) {

			if (preg_match_all('/[\r\n]startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i', $this->pdfdata, $matches, PREG_SET_ORDER, $offset) == 0) {
				$this->Error('Unable to find startxref');
			}
			$matches = array_pop($matches);
			$startxref = $matches[1];
		} elseif (strpos($this->pdfdata, 'xref', $offset) == $offset) {

			$startxref = $offset;
		} elseif (preg_match('/([0-9]+[\s][0-9]+[\s]obj)/i', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset)) {

			$startxref = $offset;
		} elseif (preg_match('/[\r\n]startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset)) {

			$startxref = $matches[1][0];
		} else {
			$this->Error('Unable to find startxref');
		}

		if (strpos($this->pdfdata, 'xref', $startxref) == $startxref) {

			$xref = $this->decodeXref($startxref, $xref);
		} else {

			$xref = $this->decodeXrefStream($startxref, $xref);
		}
		if (empty($xref)) {
			$this->Error('Unable to find xref');
		}
		return $xref;
	}


	protected function decodeXref($startxref, $xref=array()) {
		$startxref += 4;

		$offset = $startxref + strspn($this->pdfdata, "\x00\x09\x0a\x0c\x0d\x20", $startxref);

		$obj_num = 0;

		while (preg_match('/([0-9]+)[\x20]([0-9]+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])/', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset) > 0) {
			if ($matches[0][1] != $offset) {

				break;
			}
			$offset += strlen($matches[0][0]);
			if ($matches[3][0] == 'n') {

				$index = $obj_num.'_'.intval($matches[2][0]);

				if (!isset($xref['xref'][$index])) {

					$xref['xref'][$index] = intval($matches[1][0]);
				}
				++$obj_num;
			} elseif ($matches[3][0] == 'f') {
				++$obj_num;
			} else {

				$obj_num = intval($matches[1][0]);
			}
		}

		if (preg_match('/trailer[\s]*<<(.*)>>/isU', $this->pdfdata, $matches, PREG_OFFSET_CAPTURE, $offset) > 0) {
			$trailer_data = $matches[1][0];
			if (!isset($xref['trailer']) OR empty($xref['trailer'])) {

				$xref['trailer'] = array();

				if (preg_match('/Size[\s]+([0-9]+)/i', $trailer_data, $matches) > 0) {
					$xref['trailer']['size'] = intval($matches[1]);
				}
				if (preg_match('/Root[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
					$xref['trailer']['root'] = intval($matches[1]).'_'.intval($matches[2]);
				}
				if (preg_match('/Encrypt[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
					$xref['trailer']['encrypt'] = intval($matches[1]).'_'.intval($matches[2]);
				}
				if (preg_match('/Info[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
					$xref['trailer']['info'] = intval($matches[1]).'_'.intval($matches[2]);
				}
				if (preg_match('/ID[\s]*[\[][\s]*[<]([^>]*)[>][\s]*[<]([^>]*)[>]/i', $trailer_data, $matches) > 0) {
					$xref['trailer']['id'] = array();
					$xref['trailer']['id'][0] = $matches[1];
					$xref['trailer']['id'][1] = $matches[2];
				}
			}
			if (preg_match('/Prev[\s]+([0-9]+)/i', $trailer_data, $matches) > 0) {

				$xref = $this->getXrefData(intval($matches[1]), $xref);
			}
		} else {
			$this->Error('Unable to find trailer');
		}
		return $xref;
	}


	protected function decodeXrefStream($startxref, $xref=array()) {

		$xrefobj = $this->getRawObject($startxref);
		$xrefcrs = $this->getIndirectObject($xrefobj[1], $startxref, true);
		if (!isset($xref['trailer']) OR empty($xref['trailer'])) {

			$xref['trailer'] = array();
			$filltrailer = true;
		} else {
			$filltrailer = false;
		}
		if (!isset($xref['xref'])) {
			$xref['xref'] = array();
		}
		$valid_crs = false;
		$columns = 0;
		$sarr = $xrefcrs[0][1];
		if (!is_array($sarr)) {
			$sarr = array();
		}
		foreach ($sarr as $k => $v) {
			if (($v[0] == '/') AND ($v[1] == 'Type') AND (isset($sarr[($k +1)]) AND ($sarr[($k +1)][0] == '/') AND ($sarr[($k +1)][1] == 'XRef'))) {
				$valid_crs = true;
			} elseif (($v[0] == '/') AND ($v[1] == 'Index') AND (isset($sarr[($k +1)]))) {

				$index_first = intval($sarr[($k +1)][1][0][1]);

				$index_entries = intval($sarr[($k +1)][1][1][1]);
			} elseif (($v[0] == '/') AND ($v[1] == 'Prev') AND (isset($sarr[($k +1)]) AND ($sarr[($k +1)][0] == 'numeric'))) {

				$prevxref = intval($sarr[($k +1)][1]);
			} elseif (($v[0] == '/') AND ($v[1] == 'W') AND (isset($sarr[($k +1)]))) {

				$wb = array();
				$wb[0] = intval($sarr[($k +1)][1][0][1]);
				$wb[1] = intval($sarr[($k +1)][1][1][1]);
				$wb[2] = intval($sarr[($k +1)][1][2][1]);
			} elseif (($v[0] == '/') AND ($v[1] == 'DecodeParms') AND (isset($sarr[($k +1)][1]))) {
				$decpar = $sarr[($k +1)][1];
				foreach ($decpar as $kdc => $vdc) {
					if (($vdc[0] == '/') AND ($vdc[1] == 'Columns') AND (isset($decpar[($kdc +1)]) AND ($decpar[($kdc +1)][0] == 'numeric'))) {
						$columns = intval($decpar[($kdc +1)][1]);
					} elseif (($vdc[0] == '/') AND ($vdc[1] == 'Predictor') AND (isset($decpar[($kdc +1)]) AND ($decpar[($kdc +1)][0] == 'numeric'))) {
						$predictor = intval($decpar[($kdc +1)][1]);
					}
				}
			} elseif ($filltrailer) {
				if (($v[0] == '/') AND ($v[1] == 'Size') AND (isset($sarr[($k +1)]) AND ($sarr[($k +1)][0] == 'numeric'))) {
					$xref['trailer']['size'] = $sarr[($k +1)][1];
				} elseif (($v[0] == '/') AND ($v[1] == 'Root') AND (isset($sarr[($k +1)]) AND ($sarr[($k +1)][0] == 'objref'))) {
					$xref['trailer']['root'] = $sarr[($k +1)][1];
				} elseif (($v[0] == '/') AND ($v[1] == 'Info') AND (isset($sarr[($k +1)]) AND ($sarr[($k +1)][0] == 'objref'))) {
					$xref['trailer']['info'] = $sarr[($k +1)][1];
				} elseif (($v[0] == '/') AND ($v[1] == 'Encrypt') AND (isset($sarr[($k +1)]) AND ($sarr[($k +1)][0] == 'objref'))) {
					$xref['trailer']['encrypt'] = $sarr[($k +1)][1];
				} elseif (($v[0] == '/') AND ($v[1] == 'ID') AND (isset($sarr[($k +1)]))) {
					$xref['trailer']['id'] = array();
					$xref['trailer']['id'][0] = $sarr[($k +1)][1][0][1];
					$xref['trailer']['id'][1] = $sarr[($k +1)][1][1][1];
				}
			}
		}

		if ($valid_crs AND isset($xrefcrs[1][3][0])) {

			$rowlen = ($columns + 1);

			$sdata = unpack('C*', $xrefcrs[1][3][0]);

			$sdata = array_chunk($sdata, $rowlen);

			$ddata = array();

			$prev_row = array_fill (0, $rowlen, 0);

			foreach ($sdata as $k => $row) {

				$ddata[$k] = array();

				$predictor = (10 + $row[0]);

				for ($i=1; $i<=$columns; ++$i) {

					$j = ($i - 1);
					$row_up = $prev_row[$j];
					if ($i == 1) {
						$row_left = 0;
						$row_upleft = 0;
					} else {
						$row_left = $row[($i - 1)];
						$row_upleft = $prev_row[($j - 1)];
					}
					switch ($predictor) {
						case 10: {
							$ddata[$k][$j] = $row[$i];
							break;
						}
						case 11: {
							$ddata[$k][$j] = (($row[$i] + $row_left) & 0xff);
							break;
						}
						case 12: {
							$ddata[$k][$j] = (($row[$i] + $row_up) & 0xff);
							break;
						}
						case 13: {
							$ddata[$k][$j] = (($row[$i] + (($row_left + $row_up) / 2)) & 0xff);
							break;
						}
						case 14: {

							$p = ($row_left + $row_up - $row_upleft);

							$pa = abs($p - $row_left);
							$pb = abs($p - $row_up);
							$pc = abs($p - $row_upleft);
							$pmin = min($pa, $pb, $pc);

							switch ($pmin) {
								case $pa: {
									$ddata[$k][$j] = (($row[$i] + $row_left) & 0xff);
									break;
								}
								case $pb: {
									$ddata[$k][$j] = (($row[$i] + $row_up) & 0xff);
									break;
								}
								case $pc: {
									$ddata[$k][$j] = (($row[$i] + $row_upleft) & 0xff);
									break;
								}
							}
							break;
						}
						default: {
							$this->Error('Unknown PNG predictor');
							break;
						}
					}
				}
				$prev_row = $ddata[$k];
			}

			$sdata = array();

			foreach ($ddata as $k => $row) {

				$sdata[$k] = array(0, 0, 0);
				if ($wb[0] == 0) {

					$sdata[$k][0] = 1;
				}
				$i = 0;

				for ($c = 0; $c < 3; ++$c) {

					for ($b = 0; $b < $wb[$c]; ++$b) {
						if (isset($row[$i])) {
							$sdata[$k][$c] += ($row[$i] << (($wb[$c] - 1 - $b) * 8));
						}
						++$i;
					}
				}
			}
			$ddata = array();

			if (isset($index_first)) {
				$obj_num = $index_first;
			} else {
				$obj_num = 0;
			}
			foreach ($sdata as $k => $row) {
				switch ($row[0]) {
					case 0: {
						break;
					}
					case 1: {

						$index = $obj_num.'_'.$row[2];

						if (!isset($xref['xref'][$index])) {

							$xref['xref'][$index] = $row[1];
						}
						break;
					}
					case 2: {


						$index = $row[1].'_0_'.$row[2];
						$xref['xref'][$index] = -1;
						break;
					}
					default: {
						break;
					}
				}
				++$obj_num;
			}
		}
		if (isset($prevxref)) {

			$xref = $this->getXrefData($prevxref, $xref);
		}
		return $xref;
	}


	protected function getRawObject($offset=0) {
		$objtype = '';
		$objval = '';

		$offset += strspn($this->pdfdata, "\x00\x09\x0a\x0c\x0d\x20", $offset);

		$char = $this->pdfdata[$offset];

		switch ($char) {
			case '%': {

				$next = strcspn($this->pdfdata, "\r\n", $offset);
				if ($next > 0) {
					$offset += $next;
					return $this->getRawObject($offset);
				}
				break;
			}
			case '/': {

				$objtype = $char;
				++$offset;
				if (preg_match('/^([^\x00\x09\x0a\x0c\x0d\x20\s\x28\x29\x3c\x3e\x5b\x5d\x7b\x7d\x2f\x25]+)/', substr($this->pdfdata, $offset, 256), $matches) == 1) {
					$objval = $matches[1];
					$offset += strlen($objval);
				}
				break;
			}
			case '(':
			case ')': {

				$objtype = $char;
				++$offset;
				$strpos = $offset;
				if ($char == '(') {
					$open_bracket = 1;
					while ($open_bracket > 0) {
						if (!isset($this->pdfdata[$strpos])) {
							break;
						}
						$ch = $this->pdfdata[$strpos];
						switch ($ch) {
							case '\\': {

								++$strpos;
								break;
							}
							case '(': {
								++$open_bracket;
								break;
							}
							case ')': {
								--$open_bracket;
								break;
							}
						}
						++$strpos;
					}
					$objval = substr($this->pdfdata, $offset, ($strpos - $offset - 1));
					$offset = $strpos;
				}
				break;
			}
			case '[':
			case ']': {

				$objtype = $char;
				++$offset;
				if ($char == '[') {

					$objval = array();
					do {

						$element = $this->getRawObject($offset);
						$offset = $element[2];
						$objval[] = $element;
					} while ($element[0] != ']');

					array_pop($objval);
				}
				break;
			}
			case '<':
			case '>': {
				if (isset($this->pdfdata[($offset + 1)]) AND ($this->pdfdata[($offset + 1)] == $char)) {

					$objtype = $char.$char;
					$offset += 2;
					if ($char == '<') {

						$objval = array();
						do {

							$element = $this->getRawObject($offset);
							$offset = $element[2];
							$objval[] = $element;
						} while ($element[0] != '>>');

						array_pop($objval);
					}
				} else {

					$objtype = $char;
					++$offset;
					if (($char == '<') AND (preg_match('/^([0-9A-Fa-f\x09\x0a\x0c\x0d\x20]+)>/iU', substr($this->pdfdata, $offset), $matches) == 1)) {

						$objval = strtr($matches[1], "\x09\x0a\x0c\x0d\x20", '');
						$offset += strlen($matches[0]);
					} elseif (($endpos = strpos($this->pdfdata, '>', $offset)) !== FALSE) {
						$offset = $endpos + 1;
                    }
				}
				break;
			}
			default: {
				if (substr($this->pdfdata, $offset, 6) == 'endobj') {

					$objtype = 'endobj';
					$offset += 6;
				} elseif (substr($this->pdfdata, $offset, 4) == 'null') {

					$objtype = 'null';
					$offset += 4;
					$objval = 'null';
				} elseif (substr($this->pdfdata, $offset, 4) == 'true') {

					$objtype = 'boolean';
					$offset += 4;
					$objval = 'true';
				} elseif (substr($this->pdfdata, $offset, 5) == 'false') {

					$objtype = 'boolean';
					$offset += 5;
					$objval = 'false';
				} elseif (substr($this->pdfdata, $offset, 6) == 'stream') {

					$objtype = 'stream';
					$offset += 6;
					if (preg_match('/^([\r]?[\n])/isU', substr($this->pdfdata, $offset), $matches) == 1) {
						$offset += strlen($matches[0]);
						if (preg_match('/(endstream)[\x09\x0a\x0c\x0d\x20]/isU', substr($this->pdfdata, $offset), $matches, PREG_OFFSET_CAPTURE) == 1) {
							$objval = substr($this->pdfdata, $offset, $matches[0][1]);
							$offset += $matches[1][1];
						}
					}
				} elseif (substr($this->pdfdata, $offset, 9) == 'endstream') {

					$objtype = 'endstream';
					$offset += 9;
				} elseif (preg_match('/^([0-9]+)[\s]+([0-9]+)[\s]+R/iU', substr($this->pdfdata, $offset, 33), $matches) == 1) {

					$objtype = 'objref';
					$offset += strlen($matches[0]);
					$objval = intval($matches[1]).'_'.intval($matches[2]);
				} elseif (preg_match('/^([0-9]+)[\s]+([0-9]+)[\s]+obj/iU', substr($this->pdfdata, $offset, 33), $matches) == 1) {

					$objtype = 'obj';
					$objval = intval($matches[1]).'_'.intval($matches[2]);
					$offset += strlen ($matches[0]);
				} elseif (($numlen = strspn($this->pdfdata, '+-.0123456789', $offset)) > 0) {

					$objtype = 'numeric';
					$objval = substr($this->pdfdata, $offset, $numlen);
					$offset += $numlen;
				}
				break;
			}
		}
		return array($objtype, $objval, $offset);
	}


	protected function getIndirectObject($obj_ref, $offset=0, $decoding=true) {
		$obj = explode('_', $obj_ref);
		if (($obj === false) OR (count($obj) != 2)) {
			$this->Error('Invalid object reference: '.$obj);
			return;
		}
		$objref = $obj[0].' '.$obj[1].' obj';

		$offset += strspn($this->pdfdata, '0', $offset);
		if (strpos($this->pdfdata, $objref, $offset) != $offset) {

			return array('null', 'null', $offset);
		}

		$offset += strlen($objref);

		$objdata = array();
		$i = 0;
		do {
			$oldoffset = $offset;

			$element = $this->getRawObject($offset);
			$offset = $element[2];

			if ($decoding AND ($element[0] == 'stream') AND (isset($objdata[($i - 1)][0])) AND ($objdata[($i - 1)][0] == '<<')) {
				$element[3] = $this->decodeStream($objdata[($i - 1)][1], $element[1]);
			}
			$objdata[$i] = $element;
			++$i;
		} while (($element[0] != 'endobj') AND ($offset != $oldoffset));

		array_pop($objdata);

		return $objdata;
	}


	protected function getObjectVal($obj) {
		if ($obj[0] == 'objref') {

			if (isset($this->objects[$obj[1]])) {

				return $this->objects[$obj[1]];
			} elseif (isset($this->xref[$obj[1]])) {

				$this->objects[$obj[1]] = $this->getIndirectObject($obj[1], $this->xref[$obj[1]], false);
				return $this->objects[$obj[1]];
			}
		}
		return $obj;
	}


	protected function decodeStream($sdic, $stream) {

		$slength = strlen($stream);
		if ($slength <= 0) {
			return array('', array());
		}
		$filters = array();
		foreach ($sdic as $k => $v) {
			if ($v[0] == '/') {
				if (($v[1] == 'Length') AND (isset($sdic[($k + 1)])) AND ($sdic[($k + 1)][0] == 'numeric')) {

					$declength = intval($sdic[($k + 1)][1]);
					if ($declength < $slength) {
						$stream = substr($stream, 0, $declength);
						$slength = $declength;
					}
				} elseif (($v[1] == 'Filter') AND (isset($sdic[($k + 1)]))) {

					$objval = $this->getObjectVal($sdic[($k + 1)]);
					if ($objval[0] == '/') {

						$filters[] = $objval[1];
					} elseif ($objval[0] == '[') {

						foreach ($objval[1] as $flt) {
							if ($flt[0] == '/') {
								$filters[] = $flt[1];
							}
						}
					}
				}
			}
		}

		$remaining_filters = array();
		foreach ($filters as $filter) {
			if (in_array($filter, TCPDF_FILTERS::getAvailableFilters())) {
				try {
					$stream = TCPDF_FILTERS::decodeFilter($filter, $stream);
				} catch (Exception $e) {
					$emsg = $e->getMessage();
					if ((($emsg[0] == '~') AND !$this->cfg['ignore_missing_filter_decoders'])
						OR (($emsg[0] != '~') AND !$this->cfg['ignore_filter_decoding_errors'])) {
						$this->Error($e->getMessage());
					}
				}
			} else {

				$remaining_filters[] = $filter;
			}
		}
		return array($stream, $remaining_filters);
	}


	public function Error($msg) {
		if ($this->cfg['die_for_errors']) {
			die('<strong>TCPDF_PARSER ERROR: </strong>'.$msg);
		} else {
			throw new Exception('TCPDF_PARSER ERROR: '.$msg);
		}
	}

}




