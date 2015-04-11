<?php

class CloudSearch_API {

	private $error_messages;
  private $client;

  /**
   *  get protected property (Hack function)
   */
  public function getProtectedProperty($obj, $prop) {
    $reflection = new ReflectionClass($obj);
    $property = $reflection->getProperty($prop);
    $property->setAccessible(true);
    return $property->getValue($obj);
  }

  /**
   *  set protected property (Hack function)
   */
  public function setProtectedProperty($obj, $prop, $value) {
    $reflection = new ReflectionClass($obj);
    $property = $reflection->getProperty($prop);
    $property->setAccessible(true);
    $property->setValue($obj, $value);
  }

	/**
	 *
	 * @param string $domain_name
	 */
	public function __construct($domain_name) {

    $this->client = Lift_Search::$cloud_search_client
                                ->getDomainClient($domain_name, array(
        'credentials' => Lift_Search::$cloud_search_client->getCredentials(),
    ));
    // Hack for changing search httpMethod to 'GET'
    // Search does not work right now with 'POST'
    $serviceDescription = $this->getProtectedProperty($this->client, 'serviceDescription');
    $operations = $this->getProtectedProperty($serviceDescription, 'operations');
    $operations['Search']['httpMethod'] = 'GET';
    $this->setProtectedProperty($serviceDescription, 'operations', $operations);
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
        $res = $this->client->uploadDocuments(array(
                              'documents' => $batch->convert_to_JSON(),
                              'contentType' => 'application/json'
                          ));
        return lift_cloud_array_to_object_if_assoc($res->getAll(), true);
    } catch(Aws\Common\Exception\ServiceResponseException $e) {
      $this->error_messages = $e->getMessage();
      return false;
    }
	}

	public function getErrorMessages() {
		return $this->error_messages;
	}

}