<?php

namespace Librelio\Language\SScript;

class SScriptContext {

  public $global;
  public $stack;

  function __construct($g = array())
  {
    $this->global = $g;
    $this->stack = array();
  }
  
  public function pushStack($v)
  {
    return array_push($this->stack, $v);
  }

  public function popStack()
  {
    return array_pop($this->stack);
  }

}