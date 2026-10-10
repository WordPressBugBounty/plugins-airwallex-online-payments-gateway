<?php
namespace Airwallex\Gateways;

use Airwallex\Services\Util;

trait FunnelKitIntentTrait {
	public function getUpsellPaymentIntentIds($order) {
		return $this->getUpsellIntentMeta( $order, self::AIRWALLEX_UPSELL_PAYMENT_INTENTS_META_KEY );
	}

	private function getUpsellIntentMeta( $order, $key ) {
		$raw = $order->get_meta( $key, true );
		$value = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $value ) ? $value : array();
	}

	public function getUpsellPaymentIntentSnapshot( $order, $paymentIntentId ) {
		$snapshots = $this->getUpsellIntentMeta( $order, self::AIRWALLEX_UPSELL_PAYMENT_INTENT_SNAPSHOTS_META_KEY );
		$snapshot  = $snapshots[ (string) $paymentIntentId ] ?? null;
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['offer_id'], $snapshot['amount'], $snapshot['currency'] ) || ! is_numeric( $snapshot['amount'] ) ) {
			return null;
		}
		return $snapshot;
	}

	public function upsellIntentMatches( $paymentIntent, $order, $offerId ) {
		$paymentIntentId = (string) $paymentIntent->getId();
		$recordedIds     = array_map( 'strval', $this->getUpsellPaymentIntentIds( $order ) );
		$snapshot        = $this->getUpsellPaymentIntentSnapshot( $order, $paymentIntentId );
		if ( '' === $paymentIntentId || ! in_array( $paymentIntentId, $recordedIds, true ) || null === $snapshot || (string) $snapshot['offer_id'] !== (string) $offerId ) {
			return false;
		}
		$currency = strtoupper( (string) $snapshot['currency'] );
		if ( strtoupper( (string) $paymentIntent->getCurrency() ) !== $currency || ! Util::amountsEqualAtCurrencyPrecision( (float) $paymentIntent->getAmount(), (float) $snapshot['amount'], $currency ) ) {
			return false;
		}
		$metadata = $paymentIntent->getMetadata();
		if ( isset( $metadata['wp_order_id'] ) && '' !== (string) $metadata['wp_order_id'] && (string) $metadata['wp_order_id'] !== (string) $order->get_id() ) {
			return false;
		}
		if ( isset( $metadata['wfocu_offer_id'] ) && '' !== (string) $metadata['wfocu_offer_id'] && (string) $metadata['wfocu_offer_id'] !== (string) $offerId ) {
			return false;
		}
		return true;
	}

	public function rememberConsumedUpsellIntent( $offerId, $package, $parentOrder, $newOrder, $transactionId ) {
		if ( ! is_object( $parentOrder ) || ! method_exists( $parentOrder, 'update_meta_data' ) ) {
			return;
		}
		$transactionId = (string) $transactionId;
		if ( '' === $transactionId || ! in_array( $transactionId, array_map( 'strval', $this->getUpsellPaymentIntentIds( $parentOrder ) ), true ) ) {
			return;
		}
		$this->markUpsellPaymentIntentConsumed( $parentOrder, $transactionId );
	}

	private function upsellPaymentIntentIsConsumed( $order, $paymentIntentId ) {
		$consumed = $this->consumedUpsellPaymentIntentIds( $order );
		return in_array( (string) $paymentIntentId, $consumed, true );
	}

	private function markUpsellPaymentIntentConsumed( $order, $paymentIntentId ) {
		$paymentIntentId = (string) $paymentIntentId;
		$consumed        = $this->consumedUpsellPaymentIntentIds( $order );
		if ( in_array( $paymentIntentId, $consumed, true ) ) {
			return false;
		}
		$consumed[] = $paymentIntentId;
		$order->update_meta_data( self::AIRWALLEX_UPSELL_CONSUMED_PAYMENT_INTENTS_META_KEY, wp_json_encode( array_values( $consumed ) ) );
		$order->save_meta_data();
		return true;
	}

	private function consumedUpsellPaymentIntentIds( $order ) {
		return array_map( 'strval', $this->getUpsellIntentMeta( $order, self::AIRWALLEX_UPSELL_CONSUMED_PAYMENT_INTENTS_META_KEY ) );
	}

	private function reloadOrderMeta( $order ) {
		if ( method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}
	}
}
