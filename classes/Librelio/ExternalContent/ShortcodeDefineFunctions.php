<?php

namespace Librelio\ExternalContent;

class ShortcodeDefineFunctions {


  private static $funcs = array(
    "foldername"
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

}