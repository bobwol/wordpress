<?php

namespace Librelio\DocumentUploader;

use Lift_Search;

abstract class S3DocumentUploaderHandler {

  protected $aws;
  protected $s3Client;
  protected $searchApi;

  function __construct($config)
  {
    $this->aws = $config['aws'];
    $this->s3Client = $this->aws->get_client()->get('s3');
    $this->searchApi = Lift_Search::get_search_api();
  }

  public function uploadBatch($batch)
  {
    foreach($batch->getDocuments() as $document)
    {
      foreach($document->getUploadFiles() as $uploadFile)
      {
        if(!$this->uploadDocumentFile($document, $uploadFile))
          throw new Exception("Could not upload document file: ".
                                      $uploadFile->srcPath);
      }
    }
    $res = $this->searchApi->sendBatch($batch->getDocumentsBatch());
    if(!$res)
    {
      throw new \Exception("Could not upload documents: ".
                           $this->searchApi->getErrorMessages());
    }
    return true;
  }

  protected function uploadDocumentFile($document, $uploadFile)
  {
    $result = $this->s3Client->putObject(array(
      'Bucket' => $uploadFile->destBucket,
      'Key' => $uploadFile->destKey,
      'SourceFile' => $uploadFile->srcPath
    ));
    return $result ? !!@$result['ObjectURL'] : false;
  }
}