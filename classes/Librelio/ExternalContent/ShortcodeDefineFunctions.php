<?php

namespace Librelio\ExternalContent;

class ShortcodeDefineFunctions {


  private static $funcs = array(
    "foldername", "date", "changeext"
  );

  public static function define(&$vars)
  {
    $funcs = array();
    foreach(self::$funcs as $func)
      $funcs[$func] = array(__CLASS__, $func);
    $vars = array_merge($vars, $funcs);
  }

  public static function foldername($s)
  {
    return basename(dirname($s));
  }

  public static function date($f, $t = null)
  {
    if($t === null)
      $t = time();
    if($t instanceof \DateTime)
      return $t->format($f);
    return date($f, $t);
  }

  public static function changeext($p, $new_ext)
  {
    $info = pathinfo($p);
    return ($info['dirname'] != '.' && $info['dirname'] ? 
            $info['dirname'].($info['dirname'] != '/' ? '/' : '') : '').
           basename($p, '.'.$info['extension']).$new_ext;
  }

}