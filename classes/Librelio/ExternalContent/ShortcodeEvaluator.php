<?php

namespace Librelio\ExternalContent;

use Librelio\Language\Shortcode\ShortcodeParser;
use CFPropertyList\CFPropertyList, PHPHtmlParser\Dom;

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

    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $this->type = $ext;
    switch($ext)
    {
    case 'plist':
      try {
        $pl = new CFPropertyList();
        $pl->parse($data, CFPropertyList::FORMAT_XML);
        $this->global_vars['document'] = $pl->toArray();
        $this->global_vars['content'] = $data;
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
      $global_vars[$id] = $el ? $el->innerHtml() : '';
    }
  }

  public function evalFromString($content)
  {
    $parser = new ShortcodeParser($content);
    $program = $this->eval_parser($parser);
    $program->vars = $this->global_vars;
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
    if(($id = @$shortcode['attributes']['id']) && !@$this->global_vars[$id])
      $this->requiredId($id);
    return function($program) use ($shortcode)
    {
      $attrs = $shortcode['attributes'];
      if(@$attrs['id'])
      {
        $path = explode('.', $attrs['id']);
        $v = $this->locateVariable($program->vars, $path);
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
    
    $program = $this->eval_parser($parser, array( "inForeach" => true));
    
    return function($p_program) use($var_name, $each_var_name, $program)
    {
      $var = $this->locateVariable($p_program->vars, explode('.', $var_name));
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
    return function($p) use($v) { return @$this->folderdate->format($v); };
  }
}