<?php

class Bencode {
	public static function decode($str, $pos=0) {
		return self::decodeValue($str, $pos);
	}

	public static function decodeString($str, &$pos) {
		$length = self::decodeLength($str, $pos);
		$value = substr($str, $pos, $length);
		$pos += $length;
		return $value;
	}

	public static function decodeInteger($str, &$pos) {
		$value = self::decodeUntil($str, $pos, 'e');

		if (!preg_match('#^[0-9]+$#', $value)) {
			throw new Exception("Expected numbers before ':'");
		}

		return (int)$value;
	}

	public static function decodeMap($str, &$pos) {
		$len = strlen($str);
		$map = [];

		while ($pos < $len) {
			if ($str[$pos] === 'e') {
				$pos++;
				return $map;
			}

			$key = self::decodeString($str, $pos);
			$value = self::decodeValue($str, $pos);
			$map[$key] = $value;
		}

		throw new Exception("Expected 'e' at end of dictionary");
	}

	public static function decodeList($str, &$pos) {
		$len = strlen($str);
		$list = [];

		while ($pos < $len) {
			if ($str[$pos] === 'e') {
				$pos++;
				return $list;
			}

			$value = self::decodeValue($str, $pos);
			$list[] = $value;
		}

		throw new Exception("Expected 'e' at end of list");
	}

	public static function decodeLength($str, &$pos) {
		$length = self::decodeUntil($str, $pos, ':');

		if (!preg_match('#^[0-9]+$#', $length)) {
			throw new Exception("Expected numbers before ':'");
		}

		return (int)$length;
	}

	public static function decodeUntil($str, &$pos, $expect) {
		$len = strlen($str);
		$start = $pos;

		while ($pos < $len) {
			$c = $str[$pos];
			$pos++;

			if ($c === $expect) {
				return substr($str, $start, $pos - $start - 1);
			}
		}
		
		throw new Exception("Expected '$expect' instead of end of input");
	}

	public static function decodeValue($str, &$pos) {
		$type = $str[$pos];
		$pos++;

		switch ($type) {
		case 'i': return self::decodeInteger($str, $pos);
		case 'l': return self::decodeList($str, $pos);
		case 'd': return self::decodeMap($str, $pos);
		default:
			if (!is_numeric($type)) {
				throw new Exception("Invalid bencoding type");
			}

			$pos--;
			return self::decodeString($str, $pos);
		}
	}
}
