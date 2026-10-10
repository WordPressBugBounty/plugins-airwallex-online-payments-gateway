<?php
namespace Airwallex\Gateways;

use Airwallex\Services\LogService;
use Exception;

trait FunnelKitPricingTrait {
	private function packagePricedFromOffer( $package, $offerMeta, $parentOrder ) {
		if ( ! is_array( $package ) || empty( $package['products'] ) || ! is_array( $package['products'] ) || ! is_object( $offerMeta ) || ! isset( $offerMeta->fields, $offerMeta->products ) ) {
			return null;
		}
		if ( ! is_object( WFOCU_Core()->offers ) || ! method_exists( WFOCU_Core()->offers, 'get_product_price' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$settings         = ( isset( $offerMeta->settings ) && is_object( $offerMeta->settings ) ) ? $offerMeta->settings : new \stdClass();
		$qtySelector      = ! empty( $settings->qty_selector );
		$qtyMax           = isset( $settings->qty_max ) ? absint( $settings->qty_max ) : 0;
		// FunnelKit only subtracts the cancelled parent order from a one-product offer.
		$chargeDifference = 1 === count( $package['products'] );
		$products    = array();
		$charge      = 0.0;
		$tax         = 0.0;
		foreach ( $package['products'] as $product ) {
			if ( ! is_array( $product ) || empty( $product['hash'] ) ) {
				return null;
			}
			$hash = (string) $product['hash'];
			if ( ! isset( $offerMeta->fields->{$hash}, $offerMeta->products->{$hash} ) || ! is_object( $offerMeta->fields->{$hash} ) ) {
				return null;
			}
			$fields   = $offerMeta->fields->{$hash};
			$postedId = (int) $product['id'];
			$wcProduct = wc_get_product( $postedId );
			if ( ! is_object( $wcProduct ) ) {
				return null;
			}
			$offerProductId = (int) $offerMeta->products->{$hash};
			$parentId       = method_exists( $wcProduct, 'get_parent_id' ) ? (int) $wcProduct->get_parent_id() : 0;
			if ( $postedId !== $offerProductId && $parentId !== $offerProductId ) {
				return null;
			}
			$type = method_exists( $wcProduct, 'get_type' ) ? (string) $wcProduct->get_type() : '';
			if ( 'variable' === $type || 'variable-subscription' === $type ) {
				return null;
			}
			$variationConfig = $this->offerVariationConfig( $offerMeta, $hash, $postedId );
			if ( $postedId !== $offerProductId ) {
				if ( ! isset( $offerMeta->variations->{$hash} ) || ! is_object( $variationConfig ) ) {
					return null;
				}
				if ( isset( $variationConfig->is_enable ) ) {
					$enabled = function_exists( 'wc_string_to_bool' ) ? wc_string_to_bool( $variationConfig->is_enable ) : (bool) $variationConfig->is_enable;
					if ( ! $enabled ) {
						return null;
					}
				}
			}
			$offerQty = absint( isset( $fields->quantity ) ? $fields->quantity : 0 );
			if ( $offerQty < 1 ) {
				return null;
			}
			if ( $qtySelector ) {
				$lineQty = absint( isset( $product['qty'] ) ? $product['qty'] : 0 );
				if ( $lineQty < $offerQty || 0 !== ( $lineQty % $offerQty ) ) {
					return null;
				}
				$multiplier = (int) ( $lineQty / $offerQty );
				if ( $qtyMax > 0 && $multiplier > $qtyMax ) {
					return null;
				}
			} else {
				$multiplier = 1;
				$lineQty    = $offerQty;
			}
			$options     = $this->offerPriceOptions( $fields, $variationConfig );
			$bookEach    = (float) WFOCU_Core()->offers->get_product_price( $wcProduct, $options, false, $offerMeta );
			$chargeExcl  = (float) WFOCU_Core()->offers->get_product_price( $wcProduct, $options, false, $offerMeta, $chargeDifference );
			$chargeIncl  = (float) WFOCU_Core()->offers->get_product_price( $wcProduct, $options, true, $offerMeta, $chargeDifference );
			if ( $bookEach < 0 || $chargeIncl < 0 ) {
				return null;
			}
			$book = $bookEach * $multiplier;
			$product['id']    = $postedId;
			$product['qty']   = $lineQty;
			$product['price'] = $book;
			if ( ! isset( $product['args'] ) || ! is_array( $product['args'] ) ) {
				$product['args'] = array();
			}
			$product['args']['subtotal']  = $book;
			$product['args']['total']     = $book;
			$product['old_price']         = $book;
			$product['needs_shipping']    = method_exists( $wcProduct, 'needs_shipping' ) && $wcProduct->needs_shipping();
			$product['shipping_cost_flat'] = isset( $fields->shipping_cost_flat ) ? (float) $fields->shipping_cost_flat : 0.0;
			if ( class_exists( 'WFOCU_Plugin_Compatibilities' ) && method_exists( 'WFOCU_Plugin_Compatibilities', 'get_fixed_currency_price' ) ) {
				$product['shipping_cost_flat'] = (float) \WFOCU_Plugin_Compatibilities::get_fixed_currency_price( $product['shipping_cost_flat'] );
			}
			$product['shipping_hash'] = $hash;
			$products[] = $product;
			$charge    += $chargeIncl * $multiplier;
			$tax       += ( $chargeIncl - $chargeExcl ) * $multiplier;
		}
		$shipping = $this->offerShipping( $package, $settings, $products, $parentOrder );
		if ( null === $shipping ) {
			return null;
		}
		foreach ( $products as $index => $line ) {
			unset( $products[ $index ]['needs_shipping'], $products[ $index ]['shipping_cost_flat'], $products[ $index ]['shipping_hash'] );
		}
		$package['products'] = $products;
		$package['taxes']    = $tax + $shipping['tax'];
		$package['total']    = $charge + $shipping['cost'] + $shipping['tax'];
		$package['shipping'] = $shipping['shipping'];
		if ( $package['total'] < 0 ) {
			return null;
		}
		return $package;
	}

	private function offerPriceOptions( $fields, $variationConfig ) {
		$options = new \stdClass();
		$options->quantity = absint( $fields->quantity ?? 0 );
		$discountType   = $variationConfig->discount_type ?? $fields->discount_type ?? '';
		$discountAmount = $variationConfig->discount_amount ?? $fields->discount_amount ?? 0;
		if ( class_exists( 'WFOCU_Common' ) ) {
			$discountType = \WFOCU_Common::get_discount_setting( $discountType );
		}
		$options->discount_type   = $discountType;
		$options->discount_amount = $discountAmount;
		return $options;
	}

	private function offerVariationConfig( $offerMeta, $hash, $postedId ) {
		$group = (array) ( $offerMeta->variations->{$hash} ?? array() );
		$variation = $group[ $postedId ] ?? null;
		return is_object( $variation ) ? $variation : null;
	}

	private function offerShipping( $package, $settings, array $lines, $parentOrder ) {
		if ( is_object( WFOCU_Core()->public ) && method_exists( WFOCU_Core()->public, 'is_free_shipping_in_parent' ) && WFOCU_Core()->public->is_free_shipping_in_parent() ) {
			return array( 'cost' => 0.0, 'tax' => 0.0, 'shipping' => null );
		}
		if ( ! array_filter( array_column( $lines, 'needs_shipping' ) ) ) {
			return array( 'cost' => 0.0, 'tax' => 0.0, 'shipping' => null );
		}
		if ( ! empty( $settings->ship_dynamic ) && class_exists( 'WooFunnels_UpStroke_Dynamic_Shipping' ) ) {
			return $this->dynamicOfferShipping( $parentOrder, $lines, isset( $package['shipping'] ) ? $package['shipping'] : null );
		}
		return $this->flatOfferShipping( $lines );
	}

	private function flatOfferShipping( array $lines ) {
		$cost = 0.0;
		$tax  = 0.0;
		$seen = array();
		foreach ( $lines as $line ) {
			$hash = isset( $line['shipping_hash'] ) ? (string) $line['shipping_hash'] : '';
			if ( '' === $hash || isset( $seen[ $hash ] ) || empty( $line['needs_shipping'] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$flat = isset( $line['shipping_cost_flat'] ) ? (float) $line['shipping_cost_flat'] : 0.0;
			if ( $flat <= 0 ) {
				continue;
			}
			$cost += $flat;
			if ( is_object( WFOCU_Core()->shipping ) && method_exists( WFOCU_Core()->shipping, 'get_flat_shipping_rates' ) ) {
				$tax += (float) WFOCU_Core()->shipping->get_flat_shipping_rates( $flat );
			}
		}
		if ( $cost <= 0 && $tax <= 0 ) {
			return array( 'cost' => 0.0, 'tax' => 0.0, 'shipping' => null );
		}
		$label = '';
		if ( is_object( WFOCU_Core()->data ) && method_exists( WFOCU_Core()->data, 'get_option' ) ) {
			$label = (string) WFOCU_Core()->data->get_option( 'flat_shipping_label' );
		}
		return array(
			'cost'     => $cost,
			'tax'      => $tax,
			'shipping' => array(
				'label' => $label,
				'value' => 'fixed',
				'diff'  => array( 'cost' => $cost, 'tax' => $tax ),
			),
		);
	}

	private function dynamicOfferShipping( $parentOrder, array $lines, $postedShipping ) {
		if ( ! is_object( $parentOrder ) || ! method_exists( $parentOrder, 'get_shipping_country' ) ) {
			return null;
		}
		$existing = array();
		if ( is_object( WFOCU_Core()->data ) && method_exists( WFOCU_Core()->data, 'get' ) ) {
			$fromSession = WFOCU_Core()->data->get( 'chosen_shipping_methods', array() );
			if ( is_array( $fromSession ) && array() !== $fromSession ) {
				$existing = $fromSession;
			}
		}
		if ( array() === $existing && method_exists( $parentOrder, 'get_shipping_methods' ) ) {
			$methods = $parentOrder->get_shipping_methods();
			if ( is_array( $methods ) ) {
				foreach ( $methods as $method ) {
					if ( is_object( $method ) && method_exists( $method, 'get_method_id' ) ) {
						$instanceId = method_exists( $method, 'get_instance_id' ) ? $method->get_instance_id() : '';
						$existing[] = $method->get_method_id() . ':' . $instanceId;
						break;
					}
				}
			}
		}
		$products = array();
		$batching = is_object( WFOCU_Core()->funnels ) && method_exists( WFOCU_Core()->funnels, 'get_funnel_option' ) && 'batching' === WFOCU_Core()->funnels->get_funnel_option( 'order_behavior' );
		if ( $batching && method_exists( $parentOrder, 'get_items' ) ) {
			foreach ( $parentOrder->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
					continue;
				}
				$qty = max( 1, (int) $item->get_quantity() );
				$products[] = array(
					'product_id'    => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
					'qty'           => (int) $item->get_quantity(),
					'price'         => ( method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0 ) / $qty,
					'offer_product' => false,
				);
			}
		}
		foreach ( $lines as $line ) {
			if ( empty( $line['needs_shipping'] ) ) {
				continue;
			}
			$qty = max( 1, (int) $line['qty'] );
			$products[] = array(
				'product_id'           => $line['id'],
				'qty'                  => $qty,
				'price'                => ( (float) $line['price'] ) / $qty,
				'variation_attributes' => ( isset( $line['args']['variation'] ) && is_array( $line['args']['variation'] ) ) ? $line['args']['variation'] : array(),
				'offer_product'        => true,
			);
		}
		$country  = '' !== (string) $parentOrder->get_shipping_country() ? $parentOrder->get_shipping_country() : $parentOrder->get_billing_country();
		$state    = '' !== (string) $parentOrder->get_shipping_state() ? $parentOrder->get_shipping_state() : $parentOrder->get_billing_state();
		$city     = '' !== (string) $parentOrder->get_shipping_city() ? $parentOrder->get_shipping_city() : $parentOrder->get_billing_city();
		$postcode = '' !== (string) $parentOrder->get_shipping_postcode() ? $parentOrder->get_shipping_postcode() : $parentOrder->get_billing_postcode();
		try {
			$calculated = \WooFunnels_UpStroke_Dynamic_Shipping::instance()->calculate_dynamic_shipping( $products, array( $country, $state, $city, $postcode ), $existing, $parentOrder );
		} catch ( Exception $e ) {
			LogService::getInstance()->error( 'Upsell shipping could not be calculated: ' . $e->getMessage() );
			return null;
		}
		if ( ! is_array( $calculated ) ) {
			return null;
		}
		$chosen = ( is_array( $postedShipping ) && isset( $postedShipping['value'] ) ) ? (string) $postedShipping['value'] : '';
		$free   = $this->indexedShippingRates( isset( $calculated['free_shipping'] ) ? $calculated['free_shipping'] : array() );
		if ( '' !== $chosen && isset( $free[ $chosen ] ) ) {
			return array(
				'cost'     => 0.0,
				'tax'      => 0.0,
				'shipping' => array(
					'label' => isset( $free[ $chosen ]['label'] ) ? (string) $free[ $chosen ]['label'] : '',
					'value' => $chosen,
					'diff'  => array( 'cost' => 0, 'tax' => 0 ),
				),
			);
		}
		$rates = $this->indexedShippingRates( isset( $calculated['shipping'] ) ? $calculated['shipping'] : array() );
		if ( '' === $chosen || ! isset( $rates[ $chosen ] ) ) {
			return null;
		}
		$rate     = $rates[ $chosen ];
		$prev     = ( isset( $calculated['shipping_prev'] ) && is_array( $calculated['shipping_prev'] ) ) ? $calculated['shipping_prev'] : array();
		$rateCost = isset( $rate['cost'] ) ? (float) $rate['cost'] : 0.0;
		$rateTax  = isset( $rate['shipping_tax'] ) ? (float) $rate['shipping_tax'] : 0.0;
		$prevCost = isset( $prev['cost'] ) ? (float) $prev['cost'] : 0.0;
		$prevTax  = isset( $prev['tax'] ) ? (float) $prev['tax'] : 0.0;
		$combined = ( $rateCost + $rateTax ) - ( $prevCost + $prevTax );
		$diffTax  = $combined > 0 ? ( $rateTax - $prevTax ) : 0.0;
		$diffCost = $combined > 0 ? ( $combined - $diffTax ) : 0.0;
		return array(
			'cost'     => $diffCost,
			'tax'      => $diffTax,
			'shipping' => array(
				'label'    => isset( $rate['label'] ) ? (string) $rate['label'] : '',
				'value'    => $chosen,
				'diff'     => array( 'cost' => $diffCost, 'tax' => $diffTax ),
				'override' => ! empty( $calculated['override'] ) ? 'true' : 'false',
			),
		);
	}

	private function indexedShippingRates( $shipping ) {
		$rates = array();
		if ( ! is_array( $shipping ) ) {
			return $rates;
		}
		foreach ( $shipping as $key => $entry ) {
			if ( is_array( $entry ) && ( isset( $entry['cost'] ) || isset( $entry['method'] ) ) ) {
				$rates[ (string) $key ] = $entry;
				continue;
			}
			if ( ! is_array( $entry ) ) {
				continue;
			}
			foreach ( $entry as $methodId => $data ) {
				if ( is_array( $data ) ) {
					$rates[ (string) $methodId ] = $data;
				}
			}
		}
		return $rates;
	}
}
