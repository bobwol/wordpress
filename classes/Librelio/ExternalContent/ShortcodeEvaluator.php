<?php

namespace Librelio\ExternalContent;

use Librelio\Language\Shortcode\ShortcodeParser;
use CFPropertyList\CFPropertyList, 
    CFPropertyList\CFType, CFPropertyList\CFDictionary, PHPHtmlParser\Dom;
use Librelio\Language\SScript\SScriptEvaluator;
use Librelio\Language\SScript\SScriptParser;
use Librelio\Language\SScript\SScriptContext;

class ShortcodeEvaluator {

  private $data;
  private $waurl;
  private $type;
  private $dataPtr;
  private $global_vars = array();

  private $filename;
  private $foldername;
  private $folderdate;

  protected static $shortcode_handlers = array(
    'librelio' => array(
      'default' => 'eval_sc_librelio_inst',
      'attrs' => array(
        'foreach' => 'eval_librelio_foreach_inst',
        'endforeach' => 'eval_librelio_endforeach_inst',
        'filename' => 'eval_librelio_filename_inst',
        'foldername' => 'eval_librelio_foldername_inst',
        'folderdate' => 'eval_librelio_folderdate_inst',
      )
    )
  );

  function __construct($data, $waurl)
  {
    $this->data = $data;
    $this->waurl = $waurl;


    $waurl_obj = parse_url($waurl);
    $path = @$waurl_obj['path'];

    $this->foldername = basename(dirname($path));
    $farr = explode("_", $this->foldername);
    $this->folderdate = @\DateTime::createFromFormat("Ymd", array_pop($farr));
    $this->filename = basename($path);

    $thisVars = array('filename', 'folderdate');
    foreach($thisVars as $var)
      $this->global_vars[$var] = $this->{$var};

    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $this->type = $ext;
    switch($ext)
    {
    case 'plist':
      try {
        $pl = new CFPropertyList();
        $pl->parse($data, CFPropertyList::FORMAT_XML);
        $value = $pl->getValue();
        $this->global_vars['content'] = $data;
        if($value instanceof CFDictionary)
        {
          $this->global_vars['Header'] = $value->get("Header")->toArray();
          $this->global_vars['document'] = $value->get("Items")->toArray();
        }
        else
        {
          $this->global_vars['document'] = $pl->toArray();
        }
      } catch(Exception $e) {
      }
      break;
    case 'html':
      $dom = new Dom();
      $dom->load($data);
      $this->dataPtr = $dom;
      $this->global_vars['content'] = $data;
      break;
    /* Not defined yet
    case 'xml':
      $xml = simplexml_load_string($data);
      $el = @$xml[$attr_id];
      $rep = $el ? (string)$el : '';
      break;
      */
    }
  }

  public function requiredId($id)
  {
    if($this->type == 'html')
    {
      $dom = $this->dataPtr;
      $el = $dom->getElementById($id);
      $this->global_vars[$id] = $el ? $el->innerHtml() : '';
    }
  }

  public function evalFromString($content)
  {
    $parser = new ShortcodeParser($content);
    $program = $this->eval_parser($parser);
    $program->vars = $this->global_vars;
    ShortcodeDefineFunctions::define($program->vars);
    return $program->run();
  }

  protected function eval_parser($parser, $ptr = null)
  {
    $instructions = array();
    while(($instruction = $this->eval_instruction($parser, $ptr)))
      $instructions[] = $instruction;
    return new ShortcodeEvaluatorProgram($instructions);
  }

  protected function eval_instruction($parser, $ptr = null)
  {
    if(!$parser->read())
      return false;
    if($parser->nodeType == ShortcodeParser::TYPE_CONTENT_CHUNK)
      return $this->static_echo_inst($parser->value);
    else if($parser->nodeType == ShortcodeParser::TYPE_SHORTCODE)
      return $this->eval_shortcode_inst($parser, $ptr);
  }

  protected function static_echo_inst($s)
  {
    return function($program) use($s) { return $s; };
  }

  protected function eval_shortcode_inst($parser, $ptr)
  {
    $shortcode = $parser->value;
    $handlers = self::$shortcode_handlers;
    if(($handler = @$handlers[$shortcode['name']]))
    {
      foreach($shortcode['attributes'] as $key=>$value)
      {
        if(($acb = @$handler['attrs'][$key]))
        {
          return call_user_func(array($this, $acb), $parser, $value, $ptr);
        }
      }
      if($handler['default'])
        return call_user_func(array($this, $handler['default']), $parser, 
                              $shortcode, $ptr);
    }
    return array($this, 'null_inst');
  }
  
  protected function null_inst() { return ''; }

  protected function locateVariable($vars, $path)
  {
    foreach($path as $i => $var_name)
    {
      if(!$vars)
      {
        if($i == 0)
          throw new ShortcodeSemanticError("Can't access variable from: ", 
                      implode(".", array_slice($path, 0, $i)));
      }
      $vars = @$vars[$path[$i]];
    }
    return $vars;
  }

  protected function eval_sc_librelio_inst($parser, $shortcode, $ptr)
  {
    $stmt = null;
    if(($id = @$shortcode['attributes']['id']))
    {
      if(!@$this->global_vars[$id])
        $this->requiredId($id);
      $parser = new SScriptParser($id);
      $stmt = SScriptEvaluator::parseStatement($parser);
    }
    return function($program) use ($shortcode, $stmt)
    {
      $attrs = $shortcode['attributes'];
      if(@$attrs['id'])
      {
        $evaluator = new SScriptEvaluator();
        $evaluator->setContext(new SScriptContext($program->vars));
        $v = $evaluator->evaluateStatement($stmt);
        if(is_array($v))
          $v = implode(",", $v);
        return (string)$v;
      }
      
      if(sizeof(array_keys($attrs)) > 0)
        throw new ShortcodeSemanticError("Unkown librelio shortcode!");
      $v = $program->vars['content'];
      if(is_array($v))
        $v = implode(",", $v);
      return (string)$v; // default output
    };
  }

  protected function eval_librelio_foreach_inst($parser, $value, $ptr)
  {
    $vs = explode('as', $value);
    if(sizeof($vs) != 2)
      throw new ShortcodeSyntaxError("Unexpected foreach value: ".$value);
    $var_name = trim($vs[0]);
    $each_var_name = trim($vs[1]);

    $var_parser = new SScriptParser($var_name);
    $stmt = SScriptEvaluator::parseStatement($var_parser);

    $program = $this->eval_parser($parser, array( "inForeach" => true));
    
    return function($p_program) use($var_name, $each_var_name, $program, $stmt)
    {
      $evaluator = new SScriptEvaluator();
      $evaluator->setContext(new SScriptContext($p_program->vars));
      $var = $evaluator->evaluateStatement($stmt);
      if(!is_array($var))
        throw new ShortcodeSemanticError("Variable `$var_name` is not array!");
      $r = '';
      foreach($var as $key => $val)
      {
        $program->vars = array_merge($p_program->vars, array(
          $each_var_name => $val
        ));
        $r .= $program->run();
      }
      return $r;
    };
  }
  
  protected function eval_librelio_endforeach_inst($parser, $v, $ptr)
  {
    if(!$ptr || !$ptr['inForeach'])
      throw new ShortcodeSyntaxError("Unexpected endforeach attribute!");
    return false;
  }


  // custom librelio key attrs
  protected function eval_librelio_filename_inst($parser, $v, $ptr)
  {
    return function($p) { return $this->filename; };
  }
  
  protected function eval_librelio_foldername_inst($parser, $v, $ptr)
  {
    return function($p) { return $this->foldername; };
  }
  
  protected function eval_librelio_folderdate_inst($parser, $v, $ptr)
  {
    return function($p) use($v) { return $this->folderdate ? 
                                         @$this->folderdate->format($v) : ""; };
  }
}