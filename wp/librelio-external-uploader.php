<?php

use Librelio\DocumentUploader as DU;
use PHPHtmlParser\Dom;
use CFPropertyList as PList;

function librelio_external_uploader_upload($limit = 1)
{
    $waurl = Lift_Search::__get_setting('external_url_prefix') ?: '';
    $docFileSuffix = Lift_Search::__get_setting('external_s3_doc_suffix') ?: '';
    $waurl_obj = parse_url($waurl);
    if(!$docFileSuffix)
      return;
    switch(@$waurl_obj['scheme'])
    {
      case 's3':
        $s3Bucket = @$waurl_obj['host'];
        $s3Key = trim(@$waurl_obj['path'], '/');

        $flip = 0;
        $downloadPrefix = 'AUT_'.($flip ? 'DONE' : '');
        $donePrefix = 'AUT_'.(!$flip ? 'DONE' : '');

        $config = array(
          "Bucket" => $s3Bucket,
          "s3DownloadPrefix" => ($s3Key ? $s3Key.'/' : '').$downloadPrefix.'/',
          "destDocPrefix" => $s3Key,
          "docFileSuffix" => $docFileSuffix,
          // ignoring move parameter cause proccessed files to get deleted
          "s3MoveProcessedDocumentsTo" => ($s3Key ? $s3Key.'/' : '').$donePrefix.'/',
          //"s3MoveProcessedDocumentsTo" => false, // don't delete processed files
          "aws" => Lift_Search::$aws
        );

     break;
   }
   if(!$config)
     return;

  $handler = new LibrelioS3DocumentUploaderHandler($config);
  $parser = new LibrelioS3DocumentParser(array( "handler" => $handler ));

  $uploader = new DU\DocumentUploader(array(
    "parser" => $parser,
    "handler" => $handler
  ));
  set_time_limit(5000);
error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors', 'On');
  try {
    $uploader->upload($limit);
    echo "Done!";
  } catch(Exception $exp) {
    if(!$handler->catchException($exp))
    {
      echo get_class($exp).": ".$exp->getMessage()."<br />";
      echo trace_tostr($exp->getTrace());
    }
  }
}

// debug helper
function trace_tostr($trace)
{
  $str = "";
  foreach($trace as $i=>$v)
  {
    $str .= '#'.$i.' '.$v['function'].'() '.@$v['file'].':'.@$v['line'].'<br />';
  }
  return $str;
}

class LibrelioS3DocumentParser extends DU\S3DocumentParser {
  protected function fixXMLUnsupportedEntities($xml)
  {
    return str_replace("&", "&amp;", $xml);
  }
  
  public function parseDocument($parserRef)
  {
    $xml = new XMLReader();
    $data = file_get_contents($parserRef->localDir.'/'.$parserRef->docFile);
    $data = $this->fixXMLUnsupportedEntities($data);
    if(!$xml->xml($data, null, LIBXML_NOWARNING))
      throw new Exception("Couldn't open docFile: ".$parserRef->docFile);
    while(@$xml->read())
    {
      if($xml->nodeType == XMLReader::ELEMENT &&
         $xml->name == "toc")
      {
        $ret = $this->xmlReadToc($xml);
        $xml->close();
        return $ret;
      }
    }
    $xml->close();
    throw new Exception("Unknown xml file: ".$parserRef->docFile);
  }

  protected function xmlReadToc($reader)
  {
    $ret = array();
    $entries = array();
    $depth = $reader->depth + 1;
    while(@$reader->read())
    {
      if($reader->depth == $depth)
      {
        if($reader->nodeType == XMLReader::ELEMENT &&
           $reader->name == "tocentry")
        {
          $entry = array(
            "target-doc" => $reader->getAttribute("target-doc")
          );
          $entry = array_merge($entry, 
                               $this->xmlReadElementsAsTextPairs($reader));
          $entries[] = $entry;
        }
      }
    }
    $ret['tocentry'] = $entries;
    return $ret;
  }

  protected function xmlReadElementsAsTextPairs($reader)
  {
    $ret = array();
    $name = null;
    $value = "";
    $depth = $reader->depth + 1;
    while(@$reader->read())
    {
      if($reader->depth <= $depth && $name)
        $ret[$name] = $value;
      if($reader->depth == $depth)
      {
        if($reader->nodeType == XMLReader::ELEMENT)
        {
          $name = $reader->name;
        }
        else if($reader->nodeType == XMLReader::END_ELEMENT)
        {
          $name = null;
        }
        $value = "";
      }
      else if($reader->depth > $depth)
      {
        if($reader->nodeType == XMLReader::TEXT ||
           $reader->nodeType == XMLReader::CDATA)
          $value .= $reader->value;
        else if($reader->nodeType == XMLReader::WHITESPACE ||
                $reader->nodeType == XMLReader::SIGNIFICANT_WHITESPACE)
          $value .= " ";
      }
      else
        break;
    }
    return $ret;
  }
}

class LibrelioS3DocumentUploaderHandler extends DU\S3DocumentUploaderHandler {

  private $Bucket;
  private $s3DownloadPrefix;
  private $docFileSuffix;
  private $destDocPrefix;
  private $s3MoveProcessedDocumentsTo;
  private $limit;

  function __construct($config)
  {
    parent::__construct($config);
    $this->Bucket = $config['Bucket'];
    $this->s3DownloadPrefix = $config['s3DownloadPrefix'];
    $this->docFileSuffix = $config['docFileSuffix'];
    $this->destDocPrefix = $config['destDocPrefix'];
    $this->s3MoveProcessedDocumentsTo = @$config['s3MoveProcessedDocumentsTo'];
    $this->limit = $config['limit'];

    $this->catchExceptions = array(
      'Aws\S3\Exception\AccessDeniedException' => 'catchAccessDenied'
    );
  }

  public $catchExceptions;

  public function catchException($exp)
  {
    $class = get_class($exp);
    while($class && !($call = @$this->catchExceptions[$class]))
      $class = get_parent_class($class);
    if($call)
      call_user_func(array($this, $call), $exp);
    return !!$call;
  }

  function catchAccessDenied($exp)
  {
    echo 'AccessDenied request to aws s3 with Bucket: '.$this->Bucket.'<br />';
    echo 'Method: '.$exp->getRequest()->getMethod().'<br />';
    foreach($exp->getRequest()->getQuery()->getAll() as $key => $val)
      $r[] = $key.'='.$val;
    echo implode('<br />', $r);
    die();
  }
  
  public function log($a, $b, $c)
  {
    echo "log: ";
    echo $a.' ';
    if(is_array($b) && @$b['id'])
      echo ': '.$b['id'];
    else if(is_array($b))
      var_dump($b, $c);
    else
    {
      echo ' '.$b.' ';
      var_dump($c);
    }
    echo "<br />";
    // Lift_Search::event_log(a, b, c);
        //Lift_Search::event_log( 'DocumentUploader error', $err->getMessage(), array( 'error' ) );
  }

  public function fetchDocumentsId($limit)
  {
    $result = $this->s3Client->getIterator('listObjects', array(
      "Bucket" => $this->Bucket,
      "Prefix" => $this->s3DownloadPrefix
    ));

    // group files with different 
    $ret = array();
    foreach($result as $obj)
    {
      // omit directories or empty files
      if(intval($obj['Size']) == 0)
        continue;
      $path_parts = pathinfo($obj['Key']);
      $name = basename($obj['Key'], '.'.$path_parts['extension']);
      if(isset($ret[$name]))
      {
        $ret[$name]['filesInfo'][] = $obj;
      }
      else
      {
        $doc = array(
          "name" => $name,
          "id" => "s3://".$this->Bucket."/".$path_parts['dirname'] .'/'.$name,
          "filesInfo" => array($obj)
        );
        $ret[$name] = $doc;
      }
    }
    $fret = array_values($ret);
    if($limit > 0)
      return array_slice($fret, 0, $limit);
    return $fret;
  }

  protected function mkTmpFile()
  {
    return tempnam(sys_get_temp_dir(), "LIOS3");
  }

  public function fetchDocument($documentInfo)
  {
    $files = array();
    foreach($documentInfo['filesInfo'] as $file)
    {
      $files[] = $this->s3Client->getObject(array(
        "Bucket" => $this->Bucket,
        "Key" => $file['Key'],
        "SaveAs" => $this->mkTmpFile()
      ));
    }
    
    $ret = new S3DocumentCtx();
    $ret->files = $files;
    $ret->documentInfo = $documentInfo;
    return $ret;
  }

  public function createDocumentsBatch()
  {
    return new LibrelioS3UploadDocumentsBatch();
  }
  
  public function createDocument($documentRef, $parserRef)
  {
    return new LibrelioS3UploadDocument($documentRef, $parserRef);
  }
  
  public function importFiles($parser, $parserRef, $documentRef)
  {
    $unzipDir = $parser->getLocalDir($parserRef);
    $filesInfo = $documentRef->documentInfo['filesInfo'];
    foreach($documentRef->files as $i=>$zipFile)
    {
      try {
        $finfo = $filesInfo[$i];
        $this->uncompressFile($zipFile['Body']->getUri(), $unzipDir, $finfo['Key']);
      } catch(Exception $exp) {
        echo get_class($exp).": ".$exp->getMessage()."<br />";
      }
    }
  }

  public function isDocumentFile($file)
  {
    $basename = basename($file);
    $offset_a = strlen($basename);
    $offset_b = strlen($this->docFileSuffix);
    while(--$offset_a >= 0 && --$offset_b >= 0)
      if($basename[$offset_a] != $this->docFileSuffix[$offset_b])
        break;
    return $offset_b == -1 && $offset_a >= 0;
  }

  protected function findDocumentHTMLFile($prefix, &$files)
  {
    foreach($files as $key=>$file)
    {
      if(is_string($file) && strpos($file, $prefix) === 0 && 
         strpos($file, '.html') == strlen($file) - strlen('.html'))
        return array($key, $file);
    }
    return null;
  }

  protected function traverseArray($arr, $callable)
  {
    foreach($arr as $key=>&$value)
    {
      if(call_user_func($callable, $key, $value) === false)
        break;
    }
  }

  protected function removePunctuationAndDecimal($s)
  {
    $pttrn = "/[,\\-`!@#$%\\^&*()_\\[\\]\'\"\\\\\\/0-9]/";
    return preg_replace($pttrn, "", $s);
  }

  protected function getModifiedFiles($localDir, $docFile, $files, $name, $date)
  {
    $privFiles = $files;
    $pubFiles = array();
    $pdfFile = null;

    
    // issue #27 (github.com/libreliodev/wordpress) | Move pdf file to root
    $this->traverseArray($privFiles, function($i, $file) use (&$privFiles, $name, $localDir, &$pdfFile)
      {
        $s = $name.'.pdf';
        if(is_string($file) && strpos($file, $s) === strlen($file) - strlen($s))
        {
          // $file ends with $s
          $pdfFile = $s;
          if(strlen($file) > strlen($s) &&
             $file[strlen($file) - strlen($s) - 1] == '/')
          {
            $nfile = $s;
            rename($localDir.'/'.$file, $localDir.'/'.$nfile);
            $privFiles[$i] = $nfile;
          }
          return false;
        }
      });
    
    // issue #28 (github.com/libreliodev/wordpress) | Cover maker
    $this->traverseArray($privFiles, function($idx, $file) use (&$privFiles, $name, $localDir)
      {
        $s = $name.'-cover.jpg';
        if(is_string($file) && strpos($file, $s) === strlen($file) - strlen($s))
        {
          $image = imagecreatefromjpeg($localDir.'/'.$file);
          if(!$image)
            return;
          list($width, $height) = getimagesize($localDir.'/'.$file);
          $nimgs_fn = array($name.'.png', $name.'_newsstand.png');
          $imgs_height = array(300, 1024);
          
          foreach($nimgs_fn as $i=>$img_fn)
          {
            $nh = $imgs_height[$i];
            $nw = $width / $height * $nh;
            $nimage = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($nimage, $image, 0, 0, 0, 0,
                               $nw, $nh, $width, $height);
            imagepng($nimage, $localDir.'/'.$img_fn);
          }

          unlink($localDir.'/'.$file);
          array_splice($privFiles, $idx, 1, $nimgs_fn);
          return false;
        }
      });


    // issue #29 (github.com/libreliodev/wordpress) | Add entry to magazine
    $magazine_name = $this->removePunctuationAndDecimal($name).'.plist';
    if($pdfFile)
    {
      try {
        $plist = new PList\CFPropertyList($localDir.'/'.$magazine_name);
        $tv = $plist->getValue(true);
        if($tv instanceof PList\CFArray)
          $parr = $tv;
      } catch(Exception $e) {
        $plist = new PList\CFPropertyList();
        $plist->add($parr = new PList\CFArray());
      }
      if($parr)
      {
        $parr->add($idict = new PList\CFDictionary());
        $idict->add("FileName", new PList\CFString($pdfFile));
        $idict->add("Title", new PList\CFString($name));
        $idict->add("Subtitle", new PList\CFString(""));
        $plist->saveXML($localDir.'/'.$magazine_name);
        $pubFiles[] = $magazine_name;
      }
    }
    // Remove magazine to public file
    $this->traverseArray($privFiles, function($i, $file) use (&$privFiles, $magazine_name)
      {
        if(is_string($file) && $file == $magazine_name)
        {
          array_splice($privFiles, $i, 1);
          return false;
        }
      });

    return array($pubFiles, $privFiles);
  }

  public function defineDocument($document, $parser, $parserRef, $documentRef)
  {
    $localDir = $parser->getLocalDir($parserRef);
    $documentInfo = $documentRef->documentInfo;
    $document->uniqueId = $documentInfo["id"];

    // add upload files
    $docFile = $parser->getDocumentFile($parserRef);
    $docBasename = basename($docFile);
    $name = substr($docBasename, 0, 
                   strlen($docBasename) - strlen($this->docFileSuffix));
    $prefix = ($this->destDocPrefix ? $this->destDocPrefix.'/' : '');
    $docDate = null;
    $files = $parser->getFiles($parserRef);

    // parse sub-documents
    $object = $parser->parseDocument($parserRef);
    $subdocs_p = $object['tocentry'];
    $tocPlist = new PList\CFPropertyList();
    foreach($subdocs_p as $subdoc_p)
    {
      $subdoc = new LibrelioS3UploadSubdocument();
      $target_doc = @$subdoc_p['target-doc'];
      // fetch content and date
      list($subDocFileIndex, $subDocFile) = 
            $this->findDocumentHTMLFile($target_doc, $files);
      if(!$subDocFile)
      {
        $this->log("Subdocument file not found!", "target-doc=".$target_doc, 
                   array());
        continue;
      }
      $dom = new Dom();
      $dom->loadFromFile($localDir.'/'.$subDocFile);
      $els = $dom->getElementsByTag('body');
      // content is $body variable 
      $body = sizeof($els) > 0 ? $els[0]->innerHTML() : '';
      // fetch date
      $ude_subDocFile = explode('_', $subDocFile);
      if(sizeof($ude_subDocFile) > 1)
        $date = DateTime::createFromFormat("Ymd", $ude_subDocFile[1]);
      if(!@$date)
        $date = new DateTime();
      if($docDate == null)
      {
        $docDate = $date;
        $docDatePStr = $docDate->format('Ymd'); // librelio path date format
        $prefix .= $name.'_'.$docDatePStr.'/';
      }
      // rename doc file (specified in issue #38)
      $subDocFileDestP = ($pathp['dirname'] != '.' ? 
                          $pathp['dirname'].'/' : '').$target_doc.'_'.
                          $date->format('Ymd').'_.html';
      $files[$subDocFileIndex] = array( 'src' => $subDocFile,
                                        'dest' => $subDocFileDestP );
      $subdoc->uniqueId = 's3://'.$this->Bucket.'/'.$prefix.'/'.$target_doc;
      $pathInfo = pathinfo($subDocFile);
      $ext = $pathInfo['extension'];
      $subdoc->fields = array(
        "blog_id" => 1,
        "id" => -1,
        /* not needed
        "post_author" => "",
        "post_author_name" => "",
        */
        "post_content" => $body,
        "post_date_gmt" => $date->getTimestamp(),
        "post_name" => $subDocFile,
        "post_status" => "publish",
        "post_title" => @$subdoc_p['te-title'],
        "post_type" => "external",
        "resourcename" => "/".$name.'_'.$docDatePStr.'/'.$subDocFileDestP,
                              //basename($subDocFile, '.'.$ext).'_.'.$ext,
        "site_id" => 1,
        /* not needed
        "taxonomy_category_id" => array(),
        "taxonomy_category_label" => "",
        "taxonomy_post_tag_id" => array(),
        "taxonomy_post_tag_label" => ""
        */
      );
      $document->subdocuments[] = $subdoc;

      // add to toc plist
      $tocPlist->add($idict = new PList\CFDictionary());
      $idict->add("Title", new PList\CFString(@$subdoc_p['te-title']));
      $subtitle_key = '<Subtitle>';
      if(@$subdoc_p[$subtitle_key])
      {
        $text = $subdoc_p[$subtitle_key];
        $charlen_limit = 200;
        $idict->add("Subtitle", new PList\CFString(
                                  strlen($text) > $charlen_limit ? 
                                  substr($text, 0, $charlen_limit) : $text));
      }
    }
    if($docDate == null)
    {
      $docDate = new DateTime();
      $docDatePStr = $docDate->format('Ymd'); // librelio path date format
      $prefix .= $name.'_'.$docDatePStr.'/';
    }
    // get and modify files
    list($pubFiles, $privFiles) = 
         $this->getModifiedFiles($localDir, $docFile, $files, $name, $docDatePstr);

    $tocPlistFn = 'toc.plist';
    $tocPlist->saveXML($localDir.'/'.$tocPlistFn);
    $pubFiles[] = $tocPlistFn;

    $this->addUploadFiles($document, $pubFiles, array(
      "prefix" => $prefix, "localDir" => $localDir,
      "defaultAppendBeforeExt" => '_'.$docDatePStr
    ));
    $this->addUploadFiles($document, $privFiles, array(
      "prefix" => $prefix, "localDir" => $localDir,
      "isPrivate" => true,
      "defaultAppendBeforeExt" => '_'.$docDatePStr
    ));

    // upload document file
    $udfile = new DU\S3UploadDocumentFile();
    $udfile->srcPath = $localDir.'/'.$docFile;
    $udfile->destBucket = $this->Bucket;
    // rename as specified in #23
    $pathp = pathinfo($docFile);
    $prefix2 = ($this->destDocPrefix ? $this->destDocPrefix.'/' : '').
          $this->removePunctuationAndDecimal($name).'/';
    $udfile->destKey = $prefix2.
            substr($docFile, 0, 
                   strlen($docFile) - strlen($this->docFileSuffix)).
                   '_'.$docDatePStr;
    $document->uploadFiles[] = $udfile;
  }

  protected function addUploadFiles($document, $files, $opts = array())
  {
    $prefix = $opts['prefix'];
    $localDir = $opts['localDir'];
    $isPrivate = @$opts['isPrivate'];
    $defaultAppendBeforeExt = @$opts['defaultAppendBeforeExt'];
    foreach($files as $file)
    {
      $udfile = new DU\S3UploadDocumentFile();
      $udfile->destBucket = $this->Bucket;
      if(is_array($file))
      {
        // key is specified relative to document directory
        $udfile->srcPath = $localDir.'/'.$file['src'];
        $udfile->destKey = $prefix.$file['dest'];
      }
      else
      {
        $udfile->srcPath = $localDir.'/'.$file;
        // rename as specified in #23
        $pathp = pathinfo($file);
        $udfile->destKey = $prefix.
           ($pathp['dirname'] != '.' ? $pathp['dirname'].'/' : '').
           basename($file, '.'.$pathp['extension']).
           ($defaultAppendBeforeExt ?: '').
           ($isPrivate ? '_' : '').'.'.$pathp['extension'];
      }
      $document->uploadFiles[] = $udfile;
    }
  }

  protected function uncompressFile($zipFile, $destDir, $fn)
  {
    $zip = new ZipArchive;
    if(!is_file($zipFile))
      throw new Exception("Could not find zip file at: ".$fn);
    if(@$zip->open($zipFile))
    {
      if(!@$zip->extractTo($destDir))
      {
        throw new Exception("Could not parse file: ".$fn);
      }
      $zip->close();
    }
    else
    {
      throw new Exception("Could not open zip file: ".$fn);
    }
  }

  public function freeBatch($batch)
  {
    foreach($batch->getDocuments() as $document)
    {
      $this->freeDocument($document->getDocumentRef());
    }
  }
  
  public function freeDocument($documentRef)
  {
    // move or delete files
    if($this->s3MoveProcessedDocumentsTo === false)
      return;
    foreach($documentRef->documentInfo['filesInfo'] as $file)
    {
      $key = $file['Key'];
      $relKey = substr($key, strlen($this->s3DownloadPrefix));
      if($relKey)
      {
        try {
          if($this->s3MoveProcessedDocumentsTo)
          {
            $this->s3Client->copyObject(array(
              "Bucket" => $this->Bucket,
              "CopySource" => $this->Bucket.'/'.$key,
              "Key" => $this->s3MoveProcessedDocumentsTo.$relKey
            ));
          }
          $this->s3Client->deleteObject(array(
              "Bucket" => $this->Bucket,
              "Key" => $key
         ));
        } catch(Aws\S3\Exception\NoSuchKeyException $exp) {
        }
      }
      else
      {
        throw new Exception("Unexpected file: ".$file['Key']);
      }
    }
  }

}

class LibrelioS3UploadDocument extends DU\UploadDocument {

  public $uniqueId;
  public $subdocuments;
  public $uploadFiles;

  function __construct($documentRef, $parserRef)
  {
    parent::__construct($documentRef, $parserRef);
    $this->uploadFiles = array();
    $this->subdocuments = array();
  }

  public function getUploadFiles()
  {
    return $this->uploadFiles;
  }
}

class LibrelioS3UploadSubdocument {
  public $fields;
  public $uniqueId;
}

class LibrelioS3UploadDocumentsBatch implements DU\UploadDocumentsBatch {

  const BATCH_MAX_SIZE = 5242880;
  const DOCUMENT_MAX_SIZE = 1048576;

  private $docs;
  private $docsSize;
  
  private $docsJSON;
  private $docsJSONSize;
  private $batchOpLen;

  function __construct()
  {
    $this->docs = array();
    $this->docsSize = 0;
    $this->docsJSON = array();
    $this->docsJSONSize = 0;
    $this->batchOpLen = 0;
  }

  public function add($document)
  {
    $json_arr = array();
    $optsLen = 0;
    foreach($document->subdocuments as $doc)
    {
      $data = json_encode(array(
        "type" => "add",
        "id" => $this->getDocumentIdHash($doc),
        "fields" => $doc->fields,
      ));
      if(strlen($data) + 1 > self::DOCUMENT_MAX_SIZE)
        return false;
      $json_arr[] = $data;
      $optsLen++;
    }
    $json = implode(",", $json_arr);
    $jsonSize = strlen($json);
    if($this->docsJSONSize + $jsonSize + 
               $this->docsSize + 3 > self::BATCH_MAX_SIZE)
      return false;

    $this->docsJSON[] = $json;
    $this->docsJSONSize += $jsonSize;
    $this->docs[] = $document;
    $this->docsSize++;
    $this->batchOpLen += $optsLen;
    return true;
  }
  
  public function getLength()
  {
    return $this->docsSize;
  }
  
  public function getDocuments()
  {
    return $this->docs;
  }

  public function getDocumentsBatch()
  {
    if($this->batchOpLen == 0)
      return false;
    return $this;
  }

  public function convert_to_JSON()
  {
    return "[".implode(",", $this->docsJSON)."]";
  }
  
  protected function getDocumentIdHash($document)
  {
    return 'ls3'.hash('sha384', $document->uniqueId, false);
  }
}

class S3DocumentCtx {
  public $files;
  public $documentInfo;

  function __destruct()
  {
    foreach($this->files as $file)
    {
      $path = $file['Body']->getUri();
      if(is_file($path))
        unlink($path);
    }
  }
}