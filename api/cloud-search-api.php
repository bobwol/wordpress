<?php

class CloudSearch_API {

	private $error_messages;
  private $client;

	/**
	 *
	 * @param string $domain_name
	 */
	public function __construct($domain_name) {

    $this->client = Lift_Search::$cloud_search_client
                                ->getDomainClient($domain_name, array(
        'credentials' => Lift_Search::$cloud_search_client->getCredentials(),
    ));
	}

	/**
	 * Sends the search to the CloudSearch API
	 * @param Cloud_Search_Query $query
	 */
	public function sendSearch( $query ) {
    try {
        $res = $this->client->search($query->as_aws_2013_args());
        return lift_cloud_array_to_object_if_assoc($res->getAll(), true);
    } catch(Exception $e) {
      $this->error_messages = $e->getMessage();
      return false;
    }
	}

	/**
	 * Sends the batch to the CloudSearch API
	 * @param LiftBatch $batch
	 */
	public function sendBatch( $batch ) {
    try {
        $res = $this->client->uploadDocuments(array(
                              'documents' => $batch->convert_to_JSON(),
                              'contentType' => 'application/json'
                          ));
        return lift_cloud_array_to_object_if_assoc($res->getAll(), true);
    } catch(Exception $e) {
      $this->error_messages = $e->getMessage();
      return false;
    }
	}

	public function getErrorMessages() {
		return $this->error_messages;
	}

}