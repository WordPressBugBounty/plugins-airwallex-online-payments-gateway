<?php
namespace Airwallex\Gateways;

use Airwallex\Services\Util;

trait FunnelKitSnapshotTrait {
	private function rememberUpsellPaymentIntent( $order, $paymentIntentId, $offerId, $amount, $currency, array $package ) {
		$paymentIntentIds   = $this->getUpsellPaymentIntentIds( $order );
		$paymentIntentIds[] = (string) $paymentIntentId;
		$order->update_meta_data( self::AIRWALLEX_UPSELL_PAYMENT_INTENTS_META_KEY, wp_json_encode( array_values( array_unique( $paymentIntentIds ) ) ) );

		$snapshots = $this->getUpsellIntentMeta( $order, self::AIRWALLEX_UPSELL_PAYMENT_INTENT_SNAPSHOTS_META_KEY );
		$snapshots[ (string) $paymentIntentId ] = array(
			'offer_id' => (string) $offerId,
			'amount'   => (float) $amount,
			'currency' => strtoupper( (string) $currency ),
			'package'  => $package,
		);
		$order->update_meta_data( self::AIRWALLEX_UPSELL_PAYMENT_INTENT_SNAPSHOTS_META_KEY, wp_json_encode( $snapshots ) );

		if ( class_exists( 'WFOCU_Core' ) && is_object( WFOCU_Core()->data ) ) {
			if ( method_exists( WFOCU_Core()->data, 'save' ) ) {
				WFOCU_Core()->data->save();
			}
			if ( method_exists( WFOCU_Core()->data, 'get_transient_key' ) ) {
				$sessionKey = (string) WFOCU_Core()->data->get_transient_key();
				if ( '' !== $sessionKey ) {
					$order->update_meta_data( self::AIRWALLEX_UPSELL_FUNNEL_SESSION_META_KEY, $sessionKey );
				}
			}
		}
		$order->save_meta_data();
	}

	private function upsellPackageToStore( $package ) {
		if ( ! is_array( $package ) || empty( $package['products'] ) || ! is_array( $package['products'] ) || ! isset( $package['total'] ) || ! is_numeric( $package['total'] ) ) {
			return null;
		}
		$products = array();
		foreach ( $package['products'] as $product ) {
			if ( ! is_array( $product ) || ! isset( $product['id'] ) ) {
				continue;
			}
			$products[] = array(
				'id'    => $product['id'],
				'qty'   => $product['qty'] ?? 0,
				'price' => $product['price'] ?? 0,
				'args'  => ( isset( $product['args'] ) && is_array( $product['args'] ) ) ? $product['args'] : array(),
				'hash'  => $product['hash'] ?? '',
			);
		}
		if ( array() === $products ) {
			return null;
		}
		return array(
			'products' => $products,
			'total'    => $package['total'],
			'shipping' => ( isset( $package['shipping'] ) && is_array( $package['shipping'] ) ) ? $package['shipping'] : null,
			'taxes'    => $package['taxes'] ?? 0,
		);
	}

	private function bookablePackageFromSnapshot( $snapshot ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['package'] ) || ! is_array( $snapshot['package'] ) || ! isset( $snapshot['package']['total'] ) || ! is_numeric( $snapshot['package']['total'] ) ) {
			return null;
		}
		$package  = $snapshot['package'];
		$currency = strtoupper( (string) ( $snapshot['currency'] ?? '' ) );
		if ( ! Util::amountsEqualAtCurrencyPrecision( (float) $package['total'], (float) $snapshot['amount'], $currency ) ) {
			return null;
		}
		if ( function_exists( 'wc_get_product' ) && ! empty( $package['products'] ) && is_array( $package['products'] ) ) {
			foreach ( $package['products'] as $index => $product ) {
				if ( ! empty( $product['id'] ) ) {
					$package['products'][ $index ]['data'] = wc_get_product( $product['id'] );
				}
			}
		}
		return $package;
	}
}
