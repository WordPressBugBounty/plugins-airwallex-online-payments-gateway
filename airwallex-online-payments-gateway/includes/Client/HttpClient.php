<?php

namespace Airwallex\Client;

use Exception;
use WP_Error;

class HttpClient {

	private $lastCallInfo = null;

	const ERROR_CODE_UNAUTHORIZED           = 'unauthorized';
	const HTTP_STATUS_BAD_REQUEST           = 400;
	const HTTP_STATUS_UNAUTHORIZED          = 401;
	const HTTP_STATUS_NOT_FOUND             = 404;
	const HTTP_STATUS_INTERNAL_SERVER_ERROR = 500;
	const HTTP_STATUSES_FAILED              = [400, 401, 404, 500];

	/**
	 * Send http request
	 *
	 * @param $method
	 * @param $url
	 * @param $data
	 * @param $headers
	 * @return bool|string
	 * @throws Exception
	 */
	private function httpSend( $method, $url, $data, $headers, $noResponse = false ) {
		$headers['Content-Type']  = 'application/json';
		$headers['x-api-version'] = '2020-04-30';
		if ( empty($headers['User-Agent']) ) {
			$headers['User-Agent'] = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
		}
		if ( 'POST' === $method ) {
			$response = wp_remote_post(
				$url,
				array(
					'method'      => 'POST',
					'timeout'     => 10,
					'redirection' => 5,
					'headers'     => $headers,
					'body'        => $data,
					'cookies'     => array(),
				) +
				( $noResponse ?
					array(
						'blocking'  => false,
						'transport' => class_exists('\WpOrg\Requests\Transport\Fsockopen') ? '\WpOrg\Requests\Transport\Fsockopen' : 'Requests_Transport_fsockopen',
					)
					:
					array()
				)
			);
		} else {
			$response = wp_remote_get(
				$url,
				array(
					'headers' => $headers,
				)
			);

		}
		if ( is_object( $response ) && get_class( $response ) === WP_Error::class ) {
			throw new Exception( esc_html( $response->get_error_message() ) . ' | ' . esc_html( $response->get_error_code() ) );
		}
		$this->lastCallInfo = array(
			'http_code' => wp_remote_retrieve_response_code( $response ),
		);
		return wp_remote_retrieve_body( $response );
	}


	/**
	 * Make http call
	 *
	 * @param $method
	 * @param $url
	 * @param $data
	 * @param $headers
	 * @return Response
	 * @throws Exception
	 */
	public function call( $method, $url, $data, $headers, $authorizationRetryClosure = null, $noResponse = false ) {
		$startTime = microtime( true );

		$rawResponse = $this->httpSend( $method, $url, $data, $headers, $noResponse );
		if ( $noResponse ) {
			return $rawResponse;
		}

		$responseData = json_decode( $rawResponse, true );
		if ( ! $responseData ) {
			if ( 'ok' === $rawResponse ) {
				$response              = new Response();
				$response->data        = array( 'message' => $rawResponse );
				$response->status      = $this->lastCallInfo['http_code'];
				$response->time        = round( microtime( true ) - $startTime, 3 );
				$response->requestData = $data;
				$response->requestUrl  = $url;
				return $response;
			}
			throw new Exception( 'API response invalid: ' . $rawResponse );
		}
		$response              = new Response();
		$response->data        = $responseData;
		$response->status      = $this->lastCallInfo['http_code'];
		$response->time        = round( microtime( true ) - $startTime, 3 );
		$response->requestData = $data;
		$response->requestUrl  = $url;

		if ( isset( $response->data['code'] ) && self::ERROR_CODE_UNAUTHORIZED === $response->data['code'] && ! empty( $authorizationRetryClosure ) ) {
			$headers['Authorization'] = $authorizationRetryClosure();
			return $this->call( $method, $url, $data, $headers );
		}

		return $response;
	}
}
