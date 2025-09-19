<?php

namespace Airwallex\Client;

defined( 'ABSPATH' ) || exit;

class ApplePayClient extends AbstractClient {
	public static $instance = null;
	
    /**
     * Get all the web domains that are registered for Apple Pay
	 * 
	 * @return array List of registered domains
     */
    public function getApplePayRegisteredDomains() {
        $client   = $this->getHttpClient();
		$response = $client->call(
			'GET',
			$this->getGeneralUrl(
				'pa/config/applepay/registered_domains'
			),
			null,
			[
				'Authorization' => 'Bearer ' . $this->getToken(),
			]
		);

		if ( empty( $response->data['items'] ) ) {
			return [];
		}
        
		return $response->data['items'];
    }

	/**
     * Register new web domains for Apple Pay
	 * 
	 * @param string $domain
	 * @return array List of registered domains
     */
    public function registerNewDomainForApplePay($domain) {
        $client   = $this->getHttpClient();
		$response = $client->call(
			'POST',
			$this->getGeneralUrl(
				'pa/config/applepay/registered_domains/add_items'
			),
			wp_json_encode([
				'items' => [$domain]
			]),
			[
				'Authorization' => 'Bearer ' . $this->getToken(),
			]
		);

		if ( empty( $response->data['items'] ) ) {
			return [];
		}
        
		return $response->data['items'];
    }
}
