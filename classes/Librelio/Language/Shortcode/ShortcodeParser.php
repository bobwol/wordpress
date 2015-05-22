<?php

namespace Librelio\Language\Shortcode;

class ShortcodeParser {

  const TYPE_CONTENT_CHUNK = 1;
  const TYPE_SHORTCODE = 2;

  public $nodeType;
  public $value;

  private $offset;
  private $content;

  function __construct($s)
  {
    $this->content = $s;
    $this->offset = 0;
  }

  public function read()
  {
    $offset = $this->offset;
    if(strlen($this->content) <= $offset)
      return false;
    $s = substr($this->content, $offset);
    // start code
    if($s[0] != '[')
    {
      $next = strpos($s, '[');
      if($next === false)
        $next = strlen($s);
      return $this->_set_next_content_chunk($next);
    }
    $s = substr($s, 1);
    $len = 1;
    
    try {
      $ret = array();
      // read element name
      $name = $this->shortcode_parse_element_name($s);
      $ret['name'] = $name;
      $s = substr($s, strlen($name));
      $len += strlen($name);

      // read attributes
      $attrs = array();
      while(($parsed_attr = $this->shortcode_parse_element_attr($s)))
      {
        $s = substr($s, $parsed_attr['next']);
        $len += $parsed_attr['next'];
        $attrs[$parsed_attr['name']] = $parsed_attr['value'];
      }

      $ret['attributes'] = $attrs;
    } catch(ShortcodeParserException $exp) {
      return $this->_set_next_content_chunk($len);
    }
    // remove whitespace
    while(strlen($s) > 0 && ($s[0] == ' ' || $s[0] == "\t"))
    {
      $s = substr($s, 1);
      $len++;
    }

    // end code
    if(strlen($s) == 0 || $s[0] != ']')
      return $this->_set_next_content_chunk($len);

    $this->nodeType = self::TYPE_SHORTCODE;
    $this->value = $ret;
    $this->offset = $offset + $len + 1;
    return true;
  }

  function _set_next_content_chunk($len)
  {
    $this->value = substr($this->content, $this->offset, $len);
    $this->offset = $this->offset + $len;
    $this->nodeType = self::TYPE_CONTENT_CHUNK;
    return true;
  }

  function shortcode_parse_element_name($s)
  {
    $pttrn = '/^[a-z]+/i';
    if(!preg_match($pttrn, $s, $match))
      throw new ShortcodeException('Expected a name');
    return $match[0];
  }

  function shortcode_parse_element_attr($s)
  {
    $len = 0;

    // remove whitespace
    while(strlen($s) > 0 && ($s[0] == ' ' || $s[0] == "\t"))
    {
      $s = substr($s, 1);
      $len++;
    }

    if(strlen($s) == 0 || $s[0] == ']')
      return null;

    $name_pttrn = '/^[a-z_\-]+/i';
    if(!preg_match($name_pttrn, $s, $name_match))
      throw new ShortcodeParserException('Expected a name as attribute');
    $name = $name_match[0];
    $s = substr($s, strlen($name));
    $len += strlen($name);
    $value = "";

    // remove whitespace
    while(strlen($s) > 0 && ($s[0] == ' ' || $s[0] == "\t"))
    {
      $s = substr($s, 1);
      $len++;
    }

    if(strlen($s) > 0 && $s[0] != ']')
    {
      if($s[0] != '=')
        throw new ShortcodeParserException('Expected `=` after attribute name');
      $s = substr($s, 1);
      $len++;
      // remove whitespace
      while(strlen($s) > 0 && ($s[0] == ' ' || $s[0] == "\t"))
      {
        $s = substr($s, 1);
        $len++;
      }
      if($s[0] == "'" || $s[0] == '"')
      {
        list($qstr, $qlen) = $this->shortcode_parse_quote_string($s);
        $len += $qlen;
        $value = $qstr;
      }
      else
      {
        if(!preg_match($name_pttrn, $s, $value_match))
          throw new ShortcodeParserException('Expected a value in attribute `'.$name.'`');
        $value = $value_match[0];
      }
    }
    return array(
      "next" => $len,
      "name" => $name,
      "value" => $value
    );
  }

  function shortcode_parse_quote_string($s)
  {
    $q = $s[0];
    $escaped = false;
    $v = "";

    for($i = 1, $len = strlen($s); $i < $len; ++$i)
    {
      $c = $s[$i];
      if(!$escaped)
      {
        if($q == $c)
          return array($v, $i + 1);
        if($c == "\\")
        {
          $escaped = true;
          continue;
        }
      }
      else
      {
        switch($c)
        {
        case 'n':
          $v .= "\n";
          break;
        case 't':
          $v .= "\t";
          break;
        case "\"":
        case "'":
        case "\\":
          $v .= $c;
          break;
        case "\r":
          if($i + 1 < $len && $s[$i + 1] == "\n")
            $i++;
          break;
        case "\n": // escape newline
          break;
        default:
          $v .= "\\".$c;
          break;
        }
        $escaped = false;
        continue;
      }
      $v .= $c;
    }
    throw new ShortcodeParserException('Quote `'.$q.'` is not closed');
  }
}