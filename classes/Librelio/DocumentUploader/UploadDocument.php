<?php

namespace Librelio\DocumentUploader;

abstract class UploadDocument {

  protected $documentRef;
  protected $parserRef;
  
  function __construct($documentRef, $parserRef)
  {
    $this->documentRef = $documentRef;
    $this->parserRef = $parserRef;
  }

  public function getDocumentRef()
  {
    return $this->documentRef;
  }

  public function getParserRef()
  {
    return $this->parserRef;
  }
}