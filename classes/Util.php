<?php

class Util {
  public static function decodeInt($x) {
    $x = unpack('N', $x);
    return $x[1];
  }

  public static function decodeShort($x) {
    $x = unpack('n', $x);
    return $x[1];
  }

  public static function encodeInt($x) {
    return pack('N', $x);
  }

  public static function encodeHex($raw) {
    $len = strlen($raw);
    $str = '';

    for ($i=0; $i < $len; $i++) {
      $h = dechex(ord($raw[$i]));
      $str .= str_pad($h, 2, '0', STR_PAD_LEFT);
    }

    return $str;
  }

  public static function isIPv4($ip) {
    return preg_match('#^\\d+\\.\\d+\\.\\d+\\.\\d+$#', $ip);
  }

  public static function formatSize($x) {
    if ($x < 1024) {
      return $x;
    } else if ($x < 1024 * 1024) {
      return sprintf('%0.2fK', $x / 1024);
    } else if ($x < 1024 * 1024 * 1024) {
      return sprintf('%0.2fM', $x / (1024 * 1024));
    }

    return sprintf('%0.2fG', $x / (1024 * 1024 * 1024));
  }

  public static function updateString($str, $i, $r) {
    $len = strlen($r);
    $left = substr($str, 0, $i);
    $right = substr($str, $i + $len);
    return $left . $r . $right;
  }
}
