<?php

namespace Librelio\DocumentUploader;

interface UploadDocumentsBatch {

  public function add($document);
  public function getLength();
  public function getDocuments();
  public function getDocumentsBatch();

}