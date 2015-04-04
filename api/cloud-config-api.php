<?php

require_once('cloud-schemas.php');

class Cloud_Config_API {
  protected $client;
	protected $last_error;
	protected $last_status_code;

  public function __construct($client)
  {
    $this->client = $client;
  }

	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 *
	 * @param Array $error with keys 'code' and 'message' set
	 */
	protected function set_last_error( $error ) {
		$this->last_error = $error;
	}

	protected function set_last_status_code( $status_code ) {
		$this->last_status_code = $status_code;
	}

	/**
	 * Turn a nested array into dot-separated 1 dimensional array
	 *
	 * @param $array
	 * @param string $prefix
	 * @return array
	 */
	protected function _flatten_keys( $array, $prefix = '' ) {

		$result = array( );

		foreach ( $array as $key => $value ) {

			if ( is_array( $value ) ) {

				$result += $this->_flatten_keys( $value, ( $prefix . $key . '.' ) );
			} else {

				$result[$prefix . $key] = $value;
			}
		}

		return $result;
	}

	/**
	 * Helper method to make a Configuration API request, stores error if encountered
	 *
	 * @param string $method
	 * @param array $payload
	 * @return array [response string, Cloud_Config_Request object used for request]
	 */
	protected function _make_request( $method, $options = array( ), $region = false ) {

    try {
      if($region)
        $this->client->setRegion($region);
      try {
        $ret =  $this->client->{$method}($options);
        if($ret)
        {
          $ret = lift_cloud_array_to_object_if_assoc($ret->getAll(), true);
        }
        return $ret;
      } catch(Aws\CloudSearch\Exception\CloudSearchException $e) {
		    $this->set_last_error(array(
           'code' => $e->getExceptionCode(), 
           'message' => $e->getMessage() ));
        return false;
      }
    } catch(Exception $e) {
		  $this->set_last_error(array(
           'code' => $e->getCode().'', 
           'message' => $e->getMessage() ));
      return false;
    }
	}

	/**
	 * @method DescribeDomains
	 * @return boolean
	 */
	public function DescribeDomains( $domain_names = array( ), $region = false ) {
    $opts = array(
            'DomainNames' => $domain_names
          );
		return $this->_make_request( 'DescribeDomains', $opts, $region );
	}

	/**
	 * @method CreateDomain
	 * @param string $domain_name
	 */
	public function CreateDomain( $domain_name, $region ) {
		return $this->_make_request( 'CreateDomain', array( 'DomainName' => $domain_name ), $region );
	}

	public function DescribeServiceAccessPolicies( $domain_name ) {
		return $this->_make_request( 'DescribeServiceAccessPolicies', array( 'DomainName' => $domain_name ) );
	}

	/**
	 * Define a new Rank Expression
	 *
	 * @param string $domain_name
	 * @param string $rank_name
	 * @param string $rank_expression
	 * @return array|bool|mixed
	 */
	public function DefineRankExpression( $domain_name, $rank_name, $rank_expression ) {
		$payload = array(
			'DomainName' => $domain_name,
			'RankExpression' => array(
				'RankName' => $rank_name,
				'RankExpression' => $rank_expression
			)
		);

		return $this->_make_request( 'DefineRankExpression', $payload );
	}

	/**
	 * Delete a Rank Expression
	 *
	 * @param string $domain_name
	 * @param string $rank_name
	 * @return array|bool|mixed
	 */
	public function DeleteRankExpression( $domain_name, $rank_name ) {
		$payload = array(
			'DomainName' => $domain_name,
			'RankName' => $rank_name,
		);

		return $this->_make_request( 'DeleteRankExpression', $payload );
	}

	/**
	 * @method IndexDocuments
	 * @param string $domain_name
	 *
	 * @return bool true if request completed and documents will be/are being
	 * indexed or false if request could not be completed or domain was in a
	 * status that documents could not be indexed
	 */
	public function IndexDocuments( $domain_name, $region = false ) {
		return $this->_make_request( 'IndexDocuments', array( 'DomainName' => $domain_name ), $region );
	}

	public function UpdateServiceAccessPolicies( $domain_name, $policies, $region = false ) {
		$payload = array(
			'AccessPolicies' => $policies,
			'DomainName' => $domain_name,
		);

		return $this->_make_request( 'UpdateServiceAccessPolicies', $payload, false, $region );
	}

	public function __parse_index_options( $field_type, $passed_options = array( ) ) {
		$field_types = array(
			'int' => array(
				'option_name' => 'IntOptions',
				'options' => array(
					'default' => array(
						'name' => 'DefaultValue',
						'default' => null
					)
				)
			),
			'int-array' => array(
				'option_name' => 'IntArrayOptions',
				'options' => array(
					'default' => array(
						'name' => 'DefaultValue',
						'default' => null
					)
				)
			),
			'text' => array(
				'option_name' => 'TextOptions',
				'options' => array(
					'default' => array(
						'name' => 'DefaultValue',
						'default' => null
					),
					'facet' => array(
						'name' => 'FacetEnabled',
						'default' => false
					),
					'result' => array(
						'name' => 'ResultEnabled',
						'default' => false
					),
          'highlight' => array(
            'name' => 'HighlightEnabled',
            'default' => false
          )
				)
			),
			'literal' => array(
				'option_name' => 'LiteralOptions',
				'options' => array(
					'default' => array(
						'name' => 'DefaultValue',
						'default' => null
					),
					'facet' => array(
						'name' => 'FacetEnabled',
						'default' => false
					),
					'result' => array(
						'name' => 'ResultEnabled',
						'default' => false
					),
					'search' => array(
						'name' => 'SearchEnabled',
						'default' => false
					)
				)
			),
			'literal-array' => array(
				'option_name' => 'LiteralArrayOptions',
				'options' => array(
					'default' => array(
						'name' => 'DefaultValue',
						'default' => null
					),
					'facet' => array(
						'name' => 'FacetEnabled',
						'default' => false
					),
					'result' => array(
						'name' => 'ResultEnabled',
						'default' => false
					),
					'search' => array(
						'name' => 'SearchEnabled',
						'default' => false
					)
				)
			),
		);

		$index_option_name = $field_types[$field_type]['option_name'];
		$index_options = array( );

		foreach ( $field_types[$field_type]['options'] as $option_key => $option_info ) {

			$option_name = $option_info['name'];
			$option_value = $option_info['default'];

			if ( isset( $passed_options[$option_key] ) ) {

				$option_value = $passed_options[$option_key];
			}

			if ( !is_null( $option_value ) ) {

				$index_options[$option_name] = $option_value;
			}
		}

		return array( $index_option_name => $index_options );
	}

	/**
	 * Define a new index field
	 *
	 * @param string $domain_name
	 * @param string $field_name
	 * @param string $field_type
	 * @param array $options
	 * @return bool
	 */
	public function DefineIndexField( $domain_name, $field_name, $field_type, $options = array( ) ) {
		if ( !in_array( $field_type, array( 'int', 'int-array', 'text', 'literal' ) ) ) {

			return false;
		}

		$payload = array(
			'DomainName' => $domain_name,
			'IndexField' => array(
				'IndexFieldName' => $field_name,
				'IndexFieldType' => $field_type
			)
		);

		$payload['IndexField'] += $this->__parse_index_options( $field_type, $options );

		return $this->_make_request( 'DefineIndexField', $payload );
	}

	public function DescribeIndexFields( $domain_name, $region = false ) {
		$payload = array(
			'DomainName' => $domain_name,
		);

		return $this->_make_request( 'DescribeIndexFields', $payload, $region );
	}

}
