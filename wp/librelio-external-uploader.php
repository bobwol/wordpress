<?php

use Librelio\DocumentUploader as DU;

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

        $config = array(
          "Bucket" => $s3Bucket,
          "s3DownloadPrefix" => ($s3Key ? $s3Key.'/' : '').'AUT_',
          "destDocPrefix" => $s3Key,
          "docFileSuffix" => $docFileSuffix,
          "s3MoveProcessedDocumentsTo" => ($s3Key ? $s3Key.'/' : '').'/AUT_END_',
          "limit" => $limit,
          "aws" => Lift_Search::$aws
        );

     break;
   }
   if(!$config)
     return;

  $handler = new LibrelioS3DocumentUploaderHandler($config);
  $parser = new DU\S3DocumentParser(array( "handler" => $handler ));

  $uploader = new DU\DocumentUploader(array(
    "parser" => $parser,
    "handler" => $handler
  ));
  set_time_limit(5000);
  try {
    $uploader->upload();
  } catch(Exception $exp) {
    if(!$handler->catchException($exp))
    {
      echo "Exception: ".$exp->getMessage()."<br />";
      echo trace_tostr($exp->getTrace());
    }
  }
  echo "Done!";
}

// debug helper
function trace_tostr($trace)
{
  $str = "";
  foreach($trace as $i=>$v)
  {
    $str .= '#'.$i.' '.$v['function'].'() '.$v['file'].':'.$v['line'].'<br />';
  }
  return $str;
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
    var_dump("log: ", $a, $b, $c);
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
    foreach($documentRef->files as $zipFile)
    {
      $this->uncompressFile($zipFile['Body']->getUri(), $unzipDir);
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

  public function defineDocument($document, $parser, $parserRef, $documentRef)
  {
    $localDir = $parser->getLocalDir($parserRef);
    $documentInfo = $documentRef->documentInfo;
    $document->uniqueId = $documentInfo["id"];
    $object = $parser->parseDocument($parserRef);

    $document->fields = array();
    
    // add upload files
    $docFile = $parser->getDocumentFile($parserRef);
    $docBasename = basename($docFile);
    $name = substr($docBasename, 0, 
                   strlen($docBasename) - strlen($this->docFileSuffix));
    $prefix = ($this->destDocPrefix ? $this->destDocPrefix.'/' : '').$name.'/';

    foreach($parser->getFiles($parserRef) as $file)
    {
      $udfile = new DU\S3UploadDocumentFile();
      $udfile->srcPath = $localDir.'/'.$file;
      $udfile->destBucket = $this->Bucket;
      // rename as specified in #23
      $pathp = pathinfo($file);
      $udfile->destKey = $prefix.
           ($pathp['dirname'] != '.' ? $pathp['dirname'].'/' : '').
           basename($file, '.'.$pathp['extension']).'_.'.$pathp['extension'];
      $document->uploadFiles[] = $udfile;
    }

    // upload document file
    $udfile = new DU\S3UploadDocumentFile();
    $udfile->srcPath = $localDir.'/'.$docFile;
    $udfile->destBucket = $this->Bucket;
    // rename as specified in #23
    $pathp = pathinfo($docFile);
    $udfile->destKey = $prefix.
            substr($docFile, 0, 
                   strlen($docFile) - strlen($this->docFileSuffix)).'_toc_.xml';
    $document->uploadFiles[] = $udfile;
  }

  protected function uncompressFile($zipFile, $destDir)
  {
    $zip = new ZipArchive;
    if(!is_file($zipFile))
      throw new Exception("Could not find zip file at: ".$zipFile);
    if($zip->open($zipFile))
    {
      if(!$zip->extractTo($destDir))
      {
        $zip->close();
        throw new Exception("Could not parse file: ".$zipFile);
      }
      $zip->close();
    }
    else
    {
      throw new Exception("Could not open zip file: ".$zipFile);
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
    // move files
    if($this->s3MoveProcessedDocumentsTo)
    {
      foreach($documentRef->documentInfo['filesInfo'] as $file)
      {
        $key = $file['Key'];
        $relKey = substr($key, strlen($this->s3DownloadPrefix) + 1);
        if($relKey)
        {
          $this->s3Client->copyObject(array(
            "Bucket" => $this->Bucket,
            "CopySource" => $this->Bucket.'/'.$key,
            "Key" => $this->s3MoveProcessedDocumentsTo.'/'.$relKey
          ));
          $this->s3Client->deleteObject(array(
            "Bucket" => $this->Bucket,
            "Key" => $key
          ));
        }
        else
        {
          throw new Exception("Unexpected file: ".$file['Key']);
        }
      }
    }
  }

}

class LibrelioS3UploadDocument extends DU\UploadDocument {

  public $uniqueId;
  public $fields;
  public $uploadFiles;

  function __construct($documentRef, $parserRef)
  {
    parent::__construct($documentRef, $parserRef);
    $this->uploadFiles = array();
  }

  public function getUploadFiles()
  {
    return $this->uploadFiles;
  }
}

class LibrelioS3UploadDocumentsBatch implements DU\UploadDocumentsBatch {

  const BATCH_MAX_SIZE = 5242880;
  const DOCUMENT_MAX_SIZE = 1048576;

  private $docs;
  private $docsSize;
  
  private $docsJSON;
  private $docsJSONSize;

  function __construct()
  {
    $this->docs = array();
    $this->docsSize = 0;
    $this->docsJSON = array();
    $this->docsJSONSize = 0;
  }

  public function add($document)
  {
    $json = json_encode(array(
      "type" => "add",
      "id" => $this->getDocumentIdHash($document),
      "fields" => $document->fields,
    ));
    $jsonSize = strlen($json);
    if($jsonSize + 1 > self::DOCUMENT_MAX_SIZE || 
       $this->docsJSONSize + $jsonSize + 
               $this->docsSize + 3 > self::BATCH_MAX_SIZE)
      return false;

    $this->docsJSON[] = $json;
    $this->docsJSONSize += $jsonSize;
    $this->docs[] = $document;
    $this->docsSize++;
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