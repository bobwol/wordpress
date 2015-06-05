<?php

use Aws\CloudSearch\CloudSearchClient;
use Aws\CloudSearchDomain\CloudSearchDomainClient;

class CloudSearch_API {

	private $error_messages;
  private $docClient;
  private $searchClient;

	/**
	 *
	 * @param string $domain_name
	 */
	public function __construct($domain_name) {
    /*if(!Lift_Search::$cloud_search_client)
      throw new Exception("Cloud Search Client is not initialized!");
    $this->client = Lift_Search::$cloud_search_client
                                ->getDomainClient($domain_name, array(
        'credentials' => Lift_Search::$cloud_search_client->getCredentials(),
    ));*/
    $region = Lift_Search::get_domain_region() ?: 'eu-west-1';
    $domainInfo = Lift_Search::get_domain_manager()->get_domain($domain_name);
    if(!$domainInfo)
      return;
    $this->searchClient = CloudSearchDomainClient::factory(array(
      'endpoint'=> 'https://'.$domainInfo->SearchService->Endpoint,
      'credentials'=> false,
      'version'=> '2013-01-01'
    ));
    $this->docClient = CloudSearchDomainClient::factory(array(
      'endpoint'=> 'https://'.$domainInfo->DocService->Endpoint,
      'credentials'=> Lift_Search::getAwsCredentials(),
      'version'=> '2013-01-01'
    ));
	}

	/**
	 * Sends the search to the CloudSearch API
	 * @param Cloud_Search_Query $query
	 */
	public function sendSearch( $query ) {
    try {
        $res = $this->searchClient->search($query->as_aws_2013_args());
        return lift_cloud_array_to_object_if_assoc($res->toArray(), true);
    } catch(Exception $e) {
      $this->error_messages = $e->getMessage() ?: "(sendSearch)Unkown error!";
      return false;
    }
	}

	/**
	 * Sends the batch to the CloudSearch API
	 * @param LiftBatch $batch
	 */
	public function sendBatch( $batch ) {
    try {
        $res = $this->docClient->uploadDocuments(array(
                              'documents' => $batch->convert_to_JSON(),
                              'contentType' => 'application/json'
                          ));
        return lift_cloud_array_to_object_if_assoc($res->toArray(), true);
    } catch(Aws\Common\Exception\ServiceResponseException $e) {
      $this->error_messages = $e->getMessage();
      return false;
    }
	}

	public function getErrorMessages() {
		return $this->error_messages;
	}

}