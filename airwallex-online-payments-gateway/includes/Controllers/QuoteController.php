<?php

namespace Airwallex\Controllers;

use Airwallex\Services\LogService;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\UseCase\CurrencySwitcher;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Quote as StructQuote;

defined('ABSPATH') || exit();

class QuoteController {
	const CONFIGURATION_ERROR = 'configuration_error';

	public function createQuoteForCurrencySwitching() {
        check_ajax_referer('wc-airwallex-lpm-create-quote-currency-switcher', 'security');
		
		$paymentCurrency = isset($_POST['payment_currency']) ? wc_clean(wp_unslash($_POST['payment_currency'])) : '';
		$targetCurrency = isset($_POST['target_currency']) ? wc_clean(wp_unslash($_POST['target_currency'])) : '';

		try {
			LogService::getInstance()->debug(__METHOD__ . ' - Create quote for ' . $paymentCurrency . ' and ' . $targetCurrency);
			$paymentAmount = WC()->cart->get_total(false);

			/** @var StructQuote $quote */
			$quote = (new CurrencySwitcher())
				->setPaymentAmount($paymentAmount)
				->setPaymentCurrency($paymentCurrency)
				->setTargetCurrency($targetCurrency)
				->get();

			LogService::getInstance()->debug(__METHOD__ . ' - Quote created.', $quote->getId());
			wp_send_json(
				[
					'success' => true,
					'quote' => [
						'refreshAt' => $quote->getRefreshAt(),
						'clientRate' => $quote->getClientRate(),
						'targetCurrency' => $quote->getTargetCurrency(),
						'paymentCurrency' => $quote->getPaymentCurrency(),
						'targetAmount' => $quote->getTargetAmount(),
						'paymentAmount' => $quote->getPaymentAmount(),
					],
				]
			);
		} catch (Exception $e) {
			LogService::getInstance()->error(__METHOD__ . ' - Failed to create quote:' . $e->getMessage(), compact('paymentAmount', 'paymentCurrency', 'targetCurrency'));
			wp_send_json([
				'success' => false,
				'message' => __('Failed to create quote.', 'airwallex-online-payments-gateway'),
			]);
		}
    }
}
