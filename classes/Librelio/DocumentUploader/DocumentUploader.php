<?php

namespace Librelio\DocumentUploader;

use Lift_Search;

class DocumentUploader {

  private $config;
  private $parser;
  private $handler;

  function __construct($config)
  {
    $this->config = $config;
    $this->parser = $config['parser'];
    $this->handler = $config['handler'];
  }

  function upload($limit = 0)
  {
    $limit = $limit ?: 0;
    $count = 0;
    $documentsInfo = $this->handler->fetchDocumentsId($limit);
    $uploadBatch = $this->handler->createDocumentsBatch();
    
    foreach($documentsInfo as $documentInfo)
    {
      try {
        $documentRef = $this->handler->fetchDocument($documentInfo); 
        $parserRef = $this->parser->initialize($documentRef);
      } catch(FileNotFound $exp) {
        $this->log($exp->getMessage(), $documentInfo);
        continue;
      }
      $document = $this->handler->createDocument($documentRef, $parserRef);
      
      $this->defineDocument($document, $parserRef, $documentRef);
      
      while(!$uploadBatch->add($document))
      {
        if($uploadBatch->getLength() == 0)
        {
          $this->log("Document max size exceeded!", $documentInfo);
          break;
        }
        
         $this->uploadBatch($uploadBatch);
        $this->freeBatch($uploadBatch);
        $uploadBatch = $this->handler->createDocumentsBatch();
      }
    }
    if($uploadBatch->getLength() > 0)
    {
      $this->uploadBatch($uploadBatch);
      $this->freeBatch($uploadBatch);
    }
    return $count;
  }

  function log($a, $b, $c=array())
  {
    $this->handler->log($a, $b, $c);
  }

  function defineDocument($document, $parserRef, $documentRef)
  {
    $this->handler->defineDocument($document, $this->parser, $parserRef,
                                   $documentRef);
  }

  function uploadBatch($batch)
  {
    return $this->handler->uploadBatch($batch);
  }

  function freeBatch($batch)
  {
    foreach($batch->getDocuments() as $document)
    {
      $this->parser->destroy($document->getParserRef());
    }
    $this->handler->freeBatch($batch);
  }
}