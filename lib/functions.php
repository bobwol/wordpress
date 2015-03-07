<?php

/**
 * Helper functions that may be needed.
 */
if ( !function_exists( 'array_diff_semi_assoc_recursive' ) ) {

	/**
	 * Returns the items in the first array that aren't in the second array.  Arrays
	 * are recursively compared.  If a value in the array is set by a String key, then
	 * that same key is checked in array2, otherwise, the existance of the value
	 * in array2 is checked.
	 * @param array $array1
	 * @param array $array2
	 * @return array
	 */
	function array_diff_semi_assoc_recursive( $array1, $array2 ) {
		$difference = array( );
		foreach ( $array1 as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( !isset( $array2[$key] ) ) {
					$difference[$key] = $value;
				} elseif ( !is_array( $array2[$key] ) ) {
					$new_diff = array_diff_semi_assoc_recursive( $value, ( array ) $array2[$key] );
					if ( !empty( $new_diff ) )
						$difference[$key] = $new_diff;
				} else {
					$new_diff = array_diff_semi_assoc_recursive( $value, $array2[$key] );
					if ( !empty( $new_diff ) )
						$difference[$key] = $new_diff;
				}
			} else if ( is_string( $key ) && (!array_key_exists( $key, $array2 ) || $array2[$key] != $value ) ) {
				if ( !(isset( $array2[$key] ) && is_array( $array2[$key] ) && in_array( $value, $array2[$key] )) ) {
					$difference[$key] = $value;
				}
			} elseif ( is_int( $key ) && !in_array( $value, $array2 ) ) {
				$difference[] = $value;
			}
		}
		return $difference;
	}

}

function lift_cloud_array_is_assoc($array) {
  return (bool)count(array_filter(array_keys($array), 'is_string'));
}

function lift_cloud_array_to_object_if_assoc($array, $rec = false)
{
  if(!is_array($array))
    return $array;
  if(lift_cloud_array_is_assoc($array))
  {
    $obj = (object)$array;
    if($rec)
    {
      $vars = array_keys(get_object_vars($obj));
      foreach($vars as $var)
        $obj->{$var} = lift_cloud_array_to_object_if_assoc($obj->{$var}, $rec);
    }
    return $obj;
  }
  else 
  {
    if($rec)
    {
      foreach($array as $i=>$item)
        $array[$i] = lift_cloud_array_to_object_if_assoc($item, $rec);
    }
  }
  return $array;
}

function lift_cloud_localize_func()
{
  return function($s)
  {
    return __($s, 'librelio');
  };
}
function lift_cloud_localize($s)
{
  return __($s, 'librelio');
}

class ShortcodeException extends Exception { }

function shortcode_parse($s, $offset)
{
  $s = substr($s, $offset);
  // start code
  if(strlen($s) == 0 || $s[0] != '[')
    throw new ShortcodeException('Expected `[`');
  $s = substr($s, 1);
  $len = 1;
  
  $ret = array();
  // read element name
  $name = shortcode_parse_element_name($s);
  $ret['name'] = $name;
  $s = substr($s, strlen($name));
  $len += strlen($name);
  
  // read attributes
  $attrs = array();
  while(($parsed_attr = shortcode_parse_element_attr($s)))
  {
    $s = substr($s, $parsed_attr['next']);
    $len += $parsed_attr['next'];
    $attrs[$parsed_attr['name']] = $parsed_attr['value'];
  }
  
  $ret['attributes'] = $attrs;
  // remove whitespace
  while(strlen($s) > 0 && ($s[0] == ' ' || $s[0] == "\t"))
  {
    $s = substr($s, 1);
    $len++;
  }
  
  // end code
  if(strlen($s) == 0 || $s[0] != ']')
    throw new ShortcodeException('Expected `]` at the end'); 
  $ret['strlen'] = $len + 1;
  return $ret;
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
    throw new ShortcodeException('Expected a name as attribute');
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
      throw new ShortcodeException('Expected `=` after attribute name');
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
      list($qstr, $qlen) = shortcode_parse_quote_string($s);
      $len += $qlen;
      $value = $qstr;
    }
    else
    {
      if(!preg_match($name_pttrn, $s, $value_match))
        throw new ShortcodeException('Expected a value in attribute `'.$name.'`');
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
  throw new ShortcodeException('Quote `'.$q.'` is not closed');
}