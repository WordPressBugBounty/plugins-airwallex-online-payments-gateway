<?php

namespace Airwallex\Client;

use Airwallex\Services\Util;

class AdminClient extends AbstractClient {
	public static $instance = null;
	
	public function getMerchantCountry() {
		$account = $this->getAccount();
		if ( ! empty( $account['account_details']['business_details']['address']['country_code'] ) ) {
			return $account['account_details']['business_details']['address']['country_code'];
		}

		return null;
	}

	public function finalizeConnection($env, $accessToken, $payload) {
		$client   = $this->getHttpClient();
		$response = $client->call(
			'POST',
			Util::getDomainUrl($env) . '/payment_app/plugin/api/v1/connection/finalize',
			wp_json_encode($payload),
			[
				'Authorization' => 'Bearer ' . $accessToken,
			]
		);

		return $response;
	}
}
