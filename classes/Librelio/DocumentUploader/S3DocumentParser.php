<?php

namespace Librelio\DocumentUploader;

use XMLReader;

class S3DocumentParser {

  private $handler;

  function __construct($config)
  {
    $this->handler = $config['handler'];
  }

  public function initialize($documentRef)
  {
    $dpctx = new DocumentParserCtx();
    $dpctx->documentRef = $documentRef;
    $dpctx->localDir = $this->mkTmpDir();
    $dpctx->parser = $this;
    $this->handler->importFiles($this, $dpctx, $documentRef);
    
    $dpctx->files = $this->_getFiles($dpctx);
    $dpctx->docFile = $this->detectDocumentFile($dpctx);
    return $dpctx;
  }

  public function getLocalDir($parserRef)
  {
    return $parserRef->localDir;
  }

  public function getDocumentFile($parserRef)
  {
    return $parserRef->docFile;
  }

  public function getFiles($parserRef)
  {
    return $parserRef->files;
  }

  public function parseDocument($parserRef)
  {
    $xml = new XMLReader();
    if(!$xml->open($parserRef->localDir.'/'.$parserRef->docFile, null, 
                   LIBXML_NOWARNING))
      throw new Exception("Couldn't open docFile: ".$parserRef->docFile);
    $obj = array();
    $curTagName;

    while(@$xml->read())
    {
      if($xml->nodeType == XMLReader::ELEMENT)
      {
        if($xml->depth == 1)
          $curTagName = $xml->name;
      }
      else if($xml->nodeType == XMLReader::END_ELEMENT)
      {
        if($xml->depth == 1)
          $curTagName = null;
      }
      else if($xml->nodeType == XMLReader::TEXT)
      {
        if($xml->depth == 2 && $curTagName)
        {
          $obj[$curTagName] = $xml->value;
          $curTagName = null;
        }
      }
    }

    $xml->close();
    return $obj;
  }

  public function destroy($parserRef)
  {
    if($parserRef->destroyed)
      return;
    $parserRef->destroyed = true;
    //$this->deleteDir($parserRef->localDir);
  }

  protected function mkTmpDir()
  {
    $tmpDir = tempnam(sys_get_temp_dir(), "LIOS3");
    if(is_file($tmpDir))
      unlink($tmpDir);
    mkdir($tmpDir, 0755, true);
    return $tmpDir;
  }

  protected function deleteDir($dirPath)
  {
    if(!is_dir($dirPath))
        throw new \InvalidArgumentException("$dirPath must be a directory");
    $dh = opendir($dirPath);
    while(($file = readdir($dh)) !== false)
    {
      if($file == '.' || $file == '..')
        continue;
      if (is_dir($file))
        $this->deleteDir($file);
      else
        unlink($file);
    }
    rmdir($dirPath);
  }

  protected function readFilesFromDirRec($path, $relPath = true)
  {
    if(!is_int($relPath))
      $relPath = $relPath ? strlen($path) + 1 : 0;
    $dh = opendir($path);
    if($dh === false)
      throw new Exception("Can't i");
    $files = array();
    while(($file = readdir($dh)) !== false)
    {
      if($file == '.' || $file == '..')
        continue;
      $filePath = $path.'/'.$file;
      if(is_dir($filePath))
        $files = array_merge($this->readFilesFromDirRec($filePath, $relPath), $files);
      else if(is_file($filePath))
        $files[] = $relPath == 0 ? $filePath : substr($filePath, $relPath);
    }
    closedir($dh);
    return $files;
  }

  protected function _getFiles($parserRef)
  {
    return $this->readFilesFromDirRec($parserRef->localDir, true);
  }

  protected function detectDocumentFile($parserRef)
  {
    foreach($parserRef->files as $i => $file)
    {
      if($this->handler->isDocumentFile($file))
      {
        array_splice($parserRef->files, $i, 1);
        return $file;
      }
    }
    throw new FileNotFound("Can not find document resources in this document");
  }
}

class DocumentParserCtx {
  public $documentRef;
  public $localDir;
  public $docFile;
  public $files;
  public $destroyed;
  public $parser;

  function __destruct()
  {
    if($this->parser)
      $this->parser->destroy($this);
  }
}