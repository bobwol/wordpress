<?php

namespace Librelio\Language\SScript;

class SScriptEvaluator {

  private $ctx;

  function __construct()
  {
    
  }

  public function setContext($ctx)
  {
    $this->ctx = $ctx;
  }

  public static function parseStatement($parser)
  {
    $stmt = new SScriptStatement();
    while($parser->read())
    {
      switch($parser->nodeType)
      {
      case SScriptParser::VAR_START :
        $stmt->push(new SScriptOperatorPushGlobal());
        $stmt->push(new SScriptOperatorPush($parser->value));
        $stmt->push(new SScriptOperatorGetVar());
        break;
      case SScriptParser::VAR_PROP :
        $stmt->push(new SScriptOperatorPush($parser->value));
        $stmt->push(new SScriptOperatorGetVar());
        break;
      case SScriptParser::CALL_START :
        $stmt->push(new SScriptOperatorPush(new SScriptCallStart()));
        break;
      case SScriptParser::NEXT_ARG :
        break;
      case SScriptParser::CALL_END :
        $stmt->push(new SScriptOperatorPerformCall());
        break;
      case SScriptParser::CONSTANT_STRING :
        $stmt->push(new SScriptOperatorPush($parser->value));
        break;
      }
    }
    if($stmt->getLength() <= 0)
      throw new SScriptSyntaxError("No expression!");
    return $stmt;
  }
  
  public function evaluateStatement($stmt)
  {
    $stmt->reset();
    while(($op = $stmt->shift()))
    {
      $op->run($this->ctx);
    }
    return $this->ctx->popStack();
  }

  public function evaluateString($s)
  {
    $parser = new SScriptParser($s);
    $stmt = self::parseStatement($parser);
    return $this->evaluateStatement($stmt);
  }
}

class SScriptStatement {
  public $operators;
  private $cur;
  function __construct()
  {
    $this->operators = array();
    $this->cur = 0;
  }

  public function push($op)
  {
    $this->operators[] = $op;
  }

  public function shift()
  {
    if($this->cur >= sizeof($this->operators))
      return null;
    return $this->operators[$this->cur++];
  }
  
  public function reset()
  {
    $this->cur = 0;
  }
  
  public function getLength()
  {
    return sizeof($this->operators);
  }
}

interface SScriptOperator {
  public function run($ctx);
}

class SScriptOperatorPush implements SScriptOperator {

  private $v;

  function __construct($v)
  {
    $this->v = $v;
  }
  
  public function run($ctx)
  {
    $ctx->pushStack($this->v);
  }
}

class SScriptOperatorPushGlobal implements SScriptOperator {
  
  public function run($ctx)
  {
    $ctx->pushStack($ctx->global);
  }
}

class SScriptOperatorGetVar implements SScriptOperator {
  
  public function run($ctx)
  {
    $prop = $ctx->popStack();
    $obj = $ctx->popStack();
    if(!$obj)
      throw new SScriptRuntimeError("Cannot get ".$prop." from undefined!");
    if(!isset($obj[$prop]))
      throw new SScriptRuntimeError("Object does not contain property: ".$prop);
    $value = $obj[$prop];
    $ctx->pushStack($value);
  }
}

class SScriptCallStart {

}

class SScriptOperatorPerformCall implements SScriptOperator {
  public function run($ctx)
  {
    $args = array();
    while(!(($v = $ctx->popStack()) instanceof SScriptCallStart))
      array_unshift($args, $v);
    $callable = $ctx->popStack();
    if(!$callable)
      new SScriptRuntimeError("Cannot call undefined variable");
    $ctx->pushStack(call_user_func_array($callable, $args));
  }
}