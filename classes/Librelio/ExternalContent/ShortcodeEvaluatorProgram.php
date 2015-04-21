<?php

namespace Librelio\ExternalContent;

class ShortcodeEvaluatorProgram {

  public $vars;

  private $instructions = array();

  function __construct($instructions)
  {
    $this->instructions = $instructions;
  }

  public function run()
  {
    $r = "";
    foreach($this->instructions as $instruction)
    {
      $r .= call_user_func($instruction, $this);
    }
    return $r;
  }
}