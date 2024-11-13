<?php

namespace Airwallex\Client;

use Airwallex\Gateways\Card;

class CardClient extends AbstractClient {
	public static $instance = null;

	public function getActiveCardSchemes($countryCode, $currency) {
		$client   = $this->getHttpClient();
		$response = $client->call(
			'GET',
			$this->getPciUrl(
				'pa/config/payment_method_types?' . http_build_query([
					'active' => true,
					'country_code' => $countryCode,
					'transaction_currency' => $currency,
				])
			),
			null,
			array(
				'Authorization' => 'Bearer ' . $this->getToken(),
			)
		);

		return empty($response->data['items']) ? [] : $response->data['items'];
	}
}
