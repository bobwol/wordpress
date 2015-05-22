<?php

namespace Librelio\Language\SScript;

class SScriptParser {

  const VAR_START = 1;
  const VAR_PROP = 2;
  const CALL_START = 3;
  const CALL_END = 4;
  const NEXT_ARG = 5;
  const WHITE_SPACE = 6;
  const CONSTANT_STRING = 7;

  const UNEXPECTED_CHAR = 7;

  private $s;
  private $crs;
  private $call_depth;
  private $allowed_exp;


  public $nodeType;
  public $value;
  

  function __construct($s = null)
  {
    $this->allowed_exp = 1;
    $this->crs = 0;
    $this->call_depth = 0;
    if($s)
      $this->loadString($s);
  }

  public function loadString($s)
  {
    $this->s = $s;
  }

  public function read()
  {
    $s = $this->s;
    $slen = strlen($s);
    $tmp0 = "";
    $tmp0_prop = false;
    $tmp1 = ""; // whitespace
    $quote = "";
    $qescaped = false;
    while($this->crs < $slen)
    {
      $c = $s[$this->crs];

      // quoted
      if(!$quote && ($c == "\"" || $c == "'"))
      {
        $quote = $c;
        $this->crs++;
        continue;
      }
      else if($quote)
      {
        if($qescaped || $quote != $c)
          $tmp1 .= $c;
        else
        {
          $this->crs++;
          $this->value = $tmp1;
          $this->nodeType = self::CONSTANT_STRING;
          return 1;
        }
        $this->crs++;
        continue;
      }

      // white space
      if(!$tmp0 && $this->isWhitespace($c))
      {
        $tmp1 .= $c;
        $this->crs++;
        continue;
      }
      else if($tmp1)
      {
        $this->value = $tmp1;
        $this->nodeType = self::WHITE_SPACE;
        return 1;
      }

      // variable
      if((!$tmp0 && $this->isVarStartChar($c)) ||
         ($tmp0 && $this->isVarChar($c)))
      {
        if(!$tmp0_prop && !$this->allowed_exp)
          $this->throwSyntaxError(self::UNEXPECTED_CHAR);
        $tmp0 .= $c;
        $this->crs++;
        continue;
      } 
      else if($tmp0)
      {
        $this->value = $tmp0;
        $this->nodeType = $tmp0_prop ? self::VAR_PROP : self::VAR_START;
        $this->allowed_exp = 0;
        return 1;
      }
      else if($tmp0_prop)
        $this->throwSyntaxError(self::UNEXPECTED_CHAR);


      switch($c)
      {
      case '.':
        if($this->nodeType == self::VAR_PROP ||
           $this->nodeType == self::VAR_START ||
           $this->nodeType == self::CALL_END)
          $tmp0_prop = true;
        else
          $this->throwSyntaxError(self::UNEXPECTED_CHAR);
        break;
      case '(':
        if(!$this->nodeType == self::VAR_PROP &&
           !$this->nodeType == self::VAR_START &&
           !$this->nodeType == self::CALL_END)
          $this->throwSyntaxError(self::UNEXPECTED_CHAR);
        $this->crs++;
        $this->call_depth++;
        $this->value = $c;
        $this->nodeType = self::CALL_START;
        $this->allowed_exp = 1;
        return 1;
        break;
      case ')':
        $this->call_depth--;
        if($this->call_depth < 0)
          $this->throwSyntaxError(self::UNEXPECTED_CHAR);
        $this->crs++;
        $this->nodeType = self::CALL_END;
        $this->value = $c;
        return 1;
      case ',':
        if($this->call_depth > 0)
        {
          $this->crs++;
          $this->nodeType = self::NEXT_ARG;
          $this->value = $c;
          $this->allowed_exp = 1;
          return 1;
        }
        else
          $this->throwSyntaxError(self::UNEXPECTED_CHAR);
        break;
      default:
        $this->throwSyntaxError(self::UNEXPECTED_CHAR);
        break;
      }
      $this->crs++;
    }

    if($tmp0)
    {
      $this->value = $tmp0;
      $this->nodeType = $tmp0_prop ? self::VAR_PROP : self::VAR_START;
      return 1;
    }

    if($tmp1)
    {
      $this->value = $tmp1;
      $this->nodeType = self::WHITE_SPACE;
      return 1;
    }
    
    return 0;
  }

  private function throwSyntaxError($reason)
  {
    $msg = "";
    if($reason == self::UNEXPECTED_CHAR)
    {
      $msg = "Unexpected char: ".$this->s[$this->crs];
    }
    throw new SScriptSyntaxError($msg);
  }

  private function isVarStartChar($c)
  {
    $cv = ord($c);
    return ($cv >= ord('a') && $cv <= ord('z')) ||
           ($cv >= ord('A') && $cv <= ord('Z')) ||
           $c == '_';
  }

  private function isVarChar($c)
  {
    $cv = ord($c);
    return $this->isVarStartChar($c) ||
      ($cv >= ord('0') && $cv <= ord('9'));
  }

  private function isWhitespace($c)
  {
    return $c == ' ' || $c == "\t";
  }
}