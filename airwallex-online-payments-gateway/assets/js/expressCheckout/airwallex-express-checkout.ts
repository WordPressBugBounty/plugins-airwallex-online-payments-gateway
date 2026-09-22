import type { Payment } from '@airwallex/components-sdk';

import {
	addToCart,
	getCartDetails,
	updateShippingOptions,
	updateShippingDetails,
	createOrder,
	startPaymentSession,
	getEstimatedCartDetails,
} from './api';
import {
	applePayRequiredBillingContactFields,
	applePayRequiredShippingContactFields,
	getAppleFormattedShippingOptions,
	getAppleFormattedLineItems,
	getGoogleFormattedShippingOptions,
	getSupportedNetworksForApplePay,
	getSupportedNetworksForGooglePay,
} from './utils';

// `displayLoginConfirmation`, `maskPageWhileLoading`, and
// `removePageMask` are imported below in the body where they're actually
// used; keeping them out of the named-import group here mirrors the
// original file's import ordering.
import {
	maskPageWhileLoading,
	removePageMask,
} from './utils';

interface CartDetailsResponse {
	success?: boolean;
	requiresShipping?: boolean;
	countryCode?: string;
	currencyCode?: string;
	orderInfo?: {
		total?: { amount?: number };
		displayItems?: Array<{ label: string; price: number }>;
	};
	message?: string;
}

interface ShippingResponse {
	success?: boolean;
	shipping?: {
		shippingMethods: string[];
		shippingOptions: Array<{ id: string; label: string; description?: string; amount?: number }>;
	};
	cart?: CartDetailsResponse;
	message?: string;
}

interface OrderResponse {
	result?: 'success' | 'failure' | string;
	redirect_url?: string;
	messages?: string;
	message?: string;
	order_id?: string | number;
	payload?: {
		createConsent?: boolean;
		clientSecret?: string;
		confirmationUrl?: string;
	};
	responseJSON?: {
		messages?: string;
		message?: string;
	};
}

type ExpressCheckoutWallet = 'googlepay' | 'applepay';

type ExpressCheckoutPaymentElement = {
	update?(options?: Record<string, unknown>): void;
};

const abortExpressCheckoutPayment = (
	element: ExpressCheckoutPaymentElement | undefined,
	wallet: ExpressCheckoutWallet,
): void => {
	if (wallet === 'googlepay' && element && typeof element.update === 'function') {
		element.update({
			error: {
				reason: 'PAYMENT_DATA_INVALID',
				message: 'Unable to place the order. Please try again.',
				intent: 'PAYMENT_AUTHORIZATION',
			},
		});
	}
};

/* global awxExpressCheckoutSettings, Airwallex, awxMiniCartEnabled */
jQuery(function ($) {
	'use strict';

	const paymentMode = awxCommonData.getExpressCheckoutData.hasSubscriptionProduct ? 'recurring' : 'oneoff';
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	let awxShippingOptions: any = [];
	let shippingMethods: string[] = [];
	let globalCartDetails: CartDetailsResponse = {};

	// SDK Element instances, typed via `@airwallex/components-sdk`'s
	// `Payment.*ButtonElementType` (type-only import; runtime comes
	// from the CDN-loaded `elements.bundle.min.js`). Pattern C: keep
	// methods inline; never detach into a `const m = el.method` — see
	// `.cursor/rules/javascript-this-binding.mdc`.
	//
	// The published `Payment.ApplePayButtonElementType` omits the
	// runtime `fail(error?)` method that the CDN exposes for surfacing
	// validation / shipping errors back to Apple Pay. Widen the slot
	// via intersection so the verbatim `.fail(...)` calls below stay
	// intact without introducing a runtime alias.
	type ApplePayElement = Payment.ApplePayButtonElementType & {
		fail(error?: unknown): void;
	};
	let googlepay: Payment.GooglePayButtonElementType | undefined;
	let applePay: ApplePayElement | undefined;
	let isExpressCheckoutRendering = false;
	let lastRenderedTotalKey = '';

	const getCartTotalKey = function (cartDetails: CartDetailsResponse): string {
		const raw = cartDetails?.orderInfo?.total?.amount;
		const amount = Number.isFinite(Number(raw)) ? String(Number(raw)) : '';
		const currency = cartDetails?.currencyCode || '';
		return `${amount}::${currency}`;
	};

	const renderExpressCheckoutByEvent = function (force = false): void {
		if (isExpressCheckoutRendering) {
			return;
		}
		isExpressCheckoutRendering = true;
		setTimeout(function () {
			isExpressCheckoutRendering = false;
		}, 1000)
		airwallexExpressCheckout.init(force);
	};

	const airwallexExpressCheckout = {
		init: async function (force = false): Promise<void> {
			// if settings are not available, do not proceed
			if (!('awxExpressCheckoutSettings' in window) || Object.keys(awxExpressCheckoutSettings).length === 0) {
				return;
			}

			// get cart details
			globalCartDetails = awxCommonData.getExpressCheckoutData.isVirtualProductPage ? await getEstimatedCartDetails() : await getCartDetails();

			const totalKey = getCartTotalKey(globalCartDetails);
			// Checkout `updated_checkout` omits force so we can skip a
			// destroy/remount when the total did not change. Cart, product
			// page, and mini cart always pass force=true.
			if (!force && totalKey === lastRenderedTotalKey && (applePay || googlepay)) {
				return;
			}
			lastRenderedTotalKey = totalKey;

			const { checkout } = awxExpressCheckoutSettings;

			if (awxExpressCheckoutSettings.applePayEnabled
				&& paymentMode in checkout.allowedCardNetworks.applepay
				&& checkout.allowedCardNetworks.applepay[paymentMode].length > 0) {
					// destroy the element first to prevent duplicate
				if (applePay) {
					applePay.destroy();
				}
				airwallexExpressCheckout.initApplePayButton();
			}

			if (awxExpressCheckoutSettings.googlePayEnabled
				&& paymentMode in checkout.allowedCardNetworks.googlepay
				&& checkout.allowedCardNetworks.googlepay[paymentMode].length > 0) {
					// destroy the element first to prevent duplicate
				if (googlepay) {
					googlepay.destroy();
				}
				airwallexExpressCheckout.initGooglePayButton();
			}
		},

		initGooglePayButton: async function (): Promise<void> {
			const googlePayRequestOptions = await airwallexExpressCheckout.getGooglePayRequestOptions();
			// Cast `Partial<...>` to the non-partial option type at the SDK
			// boundary so the precise `createElement('googlePayButton', ...)`
			// overload resolves; the runtime tolerates the missing-at-type
			// `amount` when `cartDetails.success` is false (the builder
			// returns `{}` in that branch and the CDN no-ops).
			googlepay = Airwallex!.createElement('googlePayButton', googlePayRequestOptions as Payment.GooglePayButtonOptions)!;
			googlepay.mount('awx-ec-google-pay-btn');

			googlepay.on('ready', () => {
				$('#awx-express-checkout-wrapper').show();
				$('.awx-google-pay-btn').show();
				if (awxExpressCheckoutSettings.isShowButtonOnProductPage) {
					$('#awx-express-checkout-button-separator').show();
				}
				$('.awx-express-checkout-error').html('').hide();
			});

			googlepay.on('click', () => {
				$('.awx-express-checkout-error').html('').hide();
			});

			googlepay!.on('shippingAddressChange', async (event) => {
				const { callbackTrigger, shippingAddress } = event.detail.intermediatePaymentData;

				// add product to the cart which is required for shipping calculation
				if (callbackTrigger == 'INITIALIZE' && awxCommonData.getExpressCheckoutData.isProductPage && awxExpressCheckoutSettings.isShowButtonOnProductPage) {
					await addToCart();
				}

				let paymentDataRequestUpdate: Partial<Payment.GooglePayButtonOptions> = {};
				// `IntermediatePaymentData.shippingAddress` is typed as
				// optional, but the CDN always emits it for the
				// `shippingAddressChange` event. Non-null assert here so
				// the verbatim call stays intact.
				const response = await updateShippingOptions(shippingAddress!) as ShippingResponse;
				if (response && response.success && response.shipping) {
					awxShippingOptions = {
						shippingMethods: response.shipping.shippingMethods,
						shippingOptions: getGoogleFormattedShippingOptions(response.shipping.shippingOptions),
					};
					paymentDataRequestUpdate.shippingOptionParameters = {
						defaultSelectedOptionId: awxShippingOptions.shippingMethods[0],
						shippingOptions: awxShippingOptions.shippingOptions
					};
					paymentDataRequestUpdate = Object.assign(paymentDataRequestUpdate, airwallexExpressCheckout.getGoogleTransactionInfo(response['cart']!))
				} else {
					awxShippingOptions = [];
					// `google.payments.api.PaymentDataError.message` is
					// declared as a required `string`, but `ShippingResponse.message`
					// is `string | undefined`. The CDN tolerates the
					// optional value verbatim; lift to a typed local.
					const shippingAddressError = {
						reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
						message: response.message,
						intent: 'SHIPPING_ADDRESS'
					} as google.payments.api.PaymentDataError;
					paymentDataRequestUpdate.error = shippingAddressError;
				}
				googlepay!.update(paymentDataRequestUpdate);
			});

			googlepay!.on('shippingMethodChange', async (event) => {
				const { shippingOptionData } = event.detail.intermediatePaymentData;

				let paymentDataRequestUpdate: Partial<Payment.GooglePayButtonOptions> = {};
				// `IntermediatePaymentData.shippingOptionData` is typed
				// as optional but the CDN always emits it for the
				// `shippingMethodChange` event.
				const response = await updateShippingDetails(shippingOptionData!.id, awxShippingOptions.shippingMethods) as ShippingResponse;
				if (response && response.success && response.cart) {
					paymentDataRequestUpdate = airwallexExpressCheckout.getGoogleTransactionInfo(response['cart']);
				} else {
					// See `shippingAddressChange` for why this is cast.
					const shippingOptionError = {
						reason: 'SHIPPING_OPTION_INVALID',
						message: response.message,
						intent: 'SHIPPING_OPTION'
					} as google.payments.api.PaymentDataError;
					paymentDataRequestUpdate.error = shippingOptionError;
				}

				googlepay!.update(paymentDataRequestUpdate);
			});

			googlepay!.on('authorized', async (event) => {
				try {
					if (awxCommonData.getExpressCheckoutData.isProductPage && awxExpressCheckoutSettings.isShowButtonOnProductPage) await addToCart();
					const order = await createOrder(event.detail.paymentData, 'googlepay') as OrderResponse;
					if (order.redirect_url) {
						location.href = order.redirect_url;
						return;
					}
					airwallexExpressCheckout.processPayment(googlepay!, order, 'googlepay');
				} catch (error) {
					removePageMask();
					abortExpressCheckoutPayment(googlepay, 'googlepay');
					$('.awx-express-checkout-error').html((error as OrderResponse)?.messages || '').show();
					console.warn(error);
				}
			});

			googlepay!.on('error', (event) => {
				console.error('There was an error', event);
			});
		},

		getGooglePayRequestOptions: async function (): Promise<Partial<Payment.GooglePayButtonOptions>> {
			const cartDetails = (awxCommonData.getExpressCheckoutData.isVirtualProductPage ? await getEstimatedCartDetails() : await getCartDetails()) as CartDetailsResponse;
			const { button, checkout, merchantInfo } = awxExpressCheckoutSettings;

			if (!cartDetails.success) {
				console.warn('Failed to get cart details');
				return {};
			}

			// PHP-sourced strings narrow to the SDK's literal unions.
			// Runtime validation already happens on the PHP side; cast
			// here is purely type-level.
			const mode = paymentMode as Payment.Mode;
			const buttonColor = button.theme as Payment.GooglePayButtonOptions['buttonColor'];
			const buttonType = button.buttonType as Payment.GooglePayButtonOptions['buttonType'];
			const allowedCardNetworks = getSupportedNetworksForGooglePay(checkout.allowedCardNetworks.googlepay[paymentMode]) as Payment.GoogleSupportedCardNetWork[];
			const callbackIntents: google.payments.api.CallbackIntent[] = ['PAYMENT_AUTHORIZATION'];

			let paymentDataRequest: Partial<Payment.GooglePayButtonOptions> = {
				mode: mode,
				buttonColor: buttonColor,
				buttonType: buttonType,
				emailRequired: true,
				billingAddressRequired: !(checkout.isVirtualPurchase && checkout.isSkipBillingForVirtual),
				billingAddressParameters: {
					format: 'FULL',
					phoneNumberRequired: checkout.requiresPhone
				},
				merchantInfo: {
					merchantName: merchantInfo.businessName,
				},
				autoCapture: checkout.autoCapture,
				allowedCardNetworks: allowedCardNetworks
			};

			if (cartDetails.requiresShipping) {
				callbackIntents.push('SHIPPING_ADDRESS', 'SHIPPING_OPTION');
				paymentDataRequest.shippingAddressRequired = true;
				paymentDataRequest.shippingOptionRequired = true;
				paymentDataRequest.shippingAddressParameters = {
					phoneNumberRequired: checkout.requiresPhone,
				};
			}
			paymentDataRequest.callbackIntents = callbackIntents;
			const transactionInfo = airwallexExpressCheckout.getGoogleTransactionInfo(cartDetails);
			paymentDataRequest = Object.assign(paymentDataRequest, transactionInfo);

			return paymentDataRequest;
		},

		getGoogleTransactionInfo: function (cartDetails: CartDetailsResponse): Partial<Payment.GooglePayButtonOptions> {
			const { checkout, transactionId } = awxExpressCheckoutSettings;

			// `cartDetails.orderInfo.displayItems` is the woocommerce
			// shape `{label, price}[]` while the SDK expects
			// `google.payments.api.DisplayItem[]`. The CDN runtime
			// accepts the loose shape verbatim; cast at the boundary so
			// the local helper return stays accurate without reshaping
			// the upstream `CartDetailsResponse` contract.
			const displayItems = cartDetails.orderInfo?.displayItems as unknown as google.payments.api.DisplayItem[] | undefined;

			return {
				amount: {
					value: cartDetails.orderInfo?.total?.amount || 0,
					currency: cartDetails.currencyCode || checkout.currencyCode,
				},
				transactionId: transactionId,
				totalPriceLabel: checkout.totalPriceLabel,
				countryCode: cartDetails.countryCode || checkout.countryCode,
				displayItems: displayItems,
			};
		},

		initApplePayButton: () => {
			const { checkout } = awxExpressCheckoutSettings;
			const applePayRequestOptions = airwallexExpressCheckout.getApplePayRequestOptions(globalCartDetails);
			// See `initGooglePayButton` for why this casts to the non-
			// partial option type at the SDK boundary.
			applePay = Airwallex!.createElement('applePayButton', applePayRequestOptions as Payment.ApplePayButtonOptions) as ApplePayElement;
			applePay.mount('awx-ec-apple-pay-btn');

			applePay.on('ready', () => {
				$('#awx-express-checkout-wrapper').show();
				$('.awx-apple-pay-btn').show();
				if (awxExpressCheckoutSettings.isShowButtonOnProductPage) {
					$('#awx-express-checkout-button-separator').show();
				}
			});

			applePay.on('click', () => {
				$('.awx-express-checkout-error').html('').hide();
			});

			applePay!.on('validateMerchant', async (event) => {
				if (awxCommonData.getExpressCheckoutData.isProductPage && awxExpressCheckoutSettings.isShowButtonOnProductPage) await addToCart();
				const merchantSession = await startPaymentSession(event.detail.validationURL) as { paymentSession?: unknown; error?: unknown };
				const { paymentSession, error } = merchantSession;

				if (paymentSession) {
					applePay!.completeValidation(paymentSession);
				} else {
					applePay!.fail(error);
				}
			});

			applePay!.on('shippingAddressChange', async (event) => {
				const cartDetails = await getCartDetails() as CartDetailsResponse;
				if (cartDetails.success) {
					if (cartDetails.requiresShipping) {
						const response = await updateShippingOptions(event.detail.shippingAddress) as ShippingResponse;
						if (response && response.success && response.shipping && response.cart) {
							const { shipping, cart } = response;
							shippingMethods = shipping.shippingMethods;
							// CDN accepts a looser `update()` payload than the
							// published `Partial<ApplePayButtonUpdateOptions>`
							// (Amount allows omitted currency; lineItems'
							// `amount` is a number rather than a string;
							// shippingMethods' `detail` is optional). Lift
							// to a typed local and cast through `unknown` so
							// the verbatim payload stays intact.
							const updateOptions = {
								amount: {
									value: cart?.orderInfo?.total?.amount || 0,
									currency: airwallexExpressCheckout.getApplePayCurrency(cart),
								},
								lineItems: getAppleFormattedLineItems(cart.orderInfo?.displayItems ?? []),
								shippingMethods: getAppleFormattedShippingOptions(shipping.shippingOptions),
								totalPriceLabel: checkout.totalPriceLabel,
							} as unknown as Partial<Payment.ApplePayButtonUpdateOptions>;
							applePay!.update(updateOptions);
						} else {
							shippingMethods = [];
							console.warn(response?.message);
							applePay!.fail({
								message: response?.message,
							});
						}
					} else {
						// See `requiresShipping` branch above for the cast rationale.
						const updateOptions = {
							amount: {
								value: cartDetails?.orderInfo?.total?.amount || 0,
								currency: airwallexExpressCheckout.getApplePayCurrency(cartDetails),
							},
							lineItems: getAppleFormattedLineItems(cartDetails.orderInfo?.displayItems ?? []),
							totalPriceLabel: checkout.totalPriceLabel,
						} as unknown as Partial<Payment.ApplePayButtonUpdateOptions>;
						applePay!.update(updateOptions);
					}
				} else {
					console.warn(cartDetails.message);
					applePay!.fail({
						message: cartDetails.message,
					});
				}
			});

			applePay!.on('shippingMethodChange', async (event) => {
				const response = await updateShippingDetails(event.detail.shippingMethod.identifier, shippingMethods) as ShippingResponse;
				if (response && response.success && response.cart) {
					const { cart } = response;
					// See `shippingAddressChange` for the cast rationale.
					const updateOptions = {
						amount: {
							value: cart?.orderInfo?.total?.amount || 0,
							currency: airwallexExpressCheckout.getApplePayCurrency(cart),
						},
						lineItems: getAppleFormattedLineItems(cart.orderInfo?.displayItems ?? []),
						totalPriceLabel: checkout.totalPriceLabel,
					} as unknown as Partial<Payment.ApplePayButtonUpdateOptions>;
					applePay!.update(updateOptions);
				} else {
					console.warn(response.message);
					applePay!.fail({
						message: response?.message,
					});
				}
			});

			applePay!.on('authorized', async (event) => {
				try {
					const payment = (event.detail.paymentData ?? {}) as Record<string, unknown>;
					payment['shippingMethods'] = shippingMethods;
					const order = await createOrder(payment, 'applepay') as OrderResponse;
					if (order.redirect_url) {
						location.href = order.redirect_url;
						return;
					}
					airwallexExpressCheckout.processPayment(applePay!, order, 'applepay');
				} catch (error) {
					removePageMask();
					abortExpressCheckoutPayment(applePay, 'applepay');
					$('.awx-express-checkout-error').html((error as OrderResponse)?.messages || '').show();
					console.warn(error);
				}
			});

			applePay!.on('error', (event) => {
				console.error('There was an error', event);
			});
		},

		// The CDN accepts an additional `origin` field on the Apple Pay
		// element options that the published `Payment.ApplePayButtonOptions`
		// type omits. Widen the return via intersection so the verbatim
		// `origin: window.location.origin` field stays without a cast.
		getApplePayCurrency: function (cartDetails?: CartDetailsResponse): string {
			const { checkout } = awxExpressCheckoutSettings;
			return cartDetails?.currencyCode || globalCartDetails.currencyCode || checkout.currencyCode || '';
		},

		getApplePayRequestOptions: (cartDetails: CartDetailsResponse): Partial<Payment.ApplePayButtonOptions> & { origin?: string } => {
			const {
				button,
				checkout,
			} = awxExpressCheckoutSettings;
			const {
				countryCode,
				currencyCode,
				orderInfo,
				requiresShipping
			} = cartDetails;

			// PHP-sourced strings narrow to the SDK's literal unions.
			const mode = paymentMode as Payment.Mode;
			const buttonColor = button.theme as Payment.ApplePayButtonOptions['buttonColor'];
			const buttonType = button.buttonType as Payment.ApplePayButtonOptions['buttonType'];
			const requiredBillingContactFields = ((checkout.isVirtualPurchase && checkout.isSkipBillingForVirtual)
				? applePayRequiredBillingContactFields.filter(item => item !== 'postalAddress')
				: applePayRequiredBillingContactFields) as Payment.ApplePayBillingContactField[];
			const requiredShippingContactFields = applePayRequiredShippingContactFields(requiresShipping) as ApplePayJS.ApplePayContactField[];
			// `getAppleFormattedLineItems` returns the loose
			// `{label, amount}` shape the CDN accepts; cast at the
			// boundary to the SDK's stricter `ApplePayLineItem`.
			const lineItems = getAppleFormattedLineItems(orderInfo?.displayItems ?? []) as unknown as ApplePayJS.ApplePayLineItem[];
			const supportedNetworks = getSupportedNetworksForApplePay(checkout.allowedCardNetworks.applepay[paymentMode]);

			return {
				mode: mode,
				buttonColor: buttonColor,
				buttonType: buttonType,
				origin: window.location.origin,
				totalPriceLabel: checkout.totalPriceLabel,
				countryCode: countryCode ? countryCode : checkout.countryCode,
				requiredBillingContactFields: requiredBillingContactFields,
				requiredShippingContactFields: requiredShippingContactFields,
				amount: {
					value: (orderInfo ? orderInfo.total?.amount : checkout.subTotal) ?? 0,
					currency: airwallexExpressCheckout.getApplePayCurrency(cartDetails),
				},
				lineItems: lineItems,
				autoCapture: checkout.autoCapture,
				supportedNetworks: supportedNetworks,
			};
		},

		processError(data: OrderResponse, err: { message?: string }): void {
			$.ajax({
				url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + data.order_id,
				method: 'GET',
				dataType: 'json',
				success: function (response: { success?: boolean; message?: string }) {
					const errMessage = response.success ? (err.message || '') : response.message;
					removePageMask();
					$('.awx-express-checkout-error').html(errMessage as string).show();
					console.warn(errMessage);
				},
				// eslint-disable-next-line @typescript-eslint/no-explicit-any
				error: function (xhr: any) {
					let errMessage: string = xhr.responseText;
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errMessage = xhr.responseJSON.message;
					}
					removePageMask();
					$('.awx-express-checkout-error').html(errMessage).show();
					console.warn(errMessage);
				}
			});
		},

		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		processPayment: (element: any, data: OrderResponse, wallet: ExpressCheckoutWallet): void => {
			maskPageWhileLoading(50000);
			if (data.result === 'success' && data.payload?.clientSecret) {
				const {
					createConsent,
					clientSecret,
					confirmationUrl,
				} = data.payload;

				if (createConsent) {
					element.confirmIntent({
						client_secret: clientSecret,
						payment_consent: {
							'next_triggered_by': 'merchant',
							'merchant_trigger_reason': 'scheduled',
						}
					}).then(() => {
						location.href = confirmationUrl as string;
					}).catch((error: { message?: string }) => {
						airwallexExpressCheckout.processError(data, error)
					});
				} else {
					element.confirmIntent({
						client_secret: clientSecret,
					}).then(() => {
						location.href = confirmationUrl as string;
					}).catch((error: { message?: string }) => {
						airwallexExpressCheckout.processError(data, error)
					});
				}
			} else {
				abortExpressCheckoutPayment(element, wallet);
				removePageMask();
				$('.awx-express-checkout-error').html(data?.messages as string).show();
				console.warn(data);
			}
		},

		/**
		 * Change the height of the button according to settings
		 */
		setButtonHeight: function (): void {
			const { button } = awxExpressCheckoutSettings;
			const height = button.height;
			$('.awx-apple-pay-btn apple-pay-button').css('--apple-pay-button-height', height);
			$('.awx-google-pay-btn button').css('height', height);
		},
	};

	// hide the express checkout gateway in the payment options
	$(document.body).on('updated_checkout', function () {
		$('.payment_method_airwallex_express_checkout').hide();
	});

	$.ajax({
		url: awxCommonData.getExpressCheckoutData.url + '&security=' + awxCommonData.getExpressCheckoutData.nonce,
		method: 'GET',
		dataType: 'json'
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
	}).done(function (expressCheckoutData: any) {
		window.awxExpressCheckoutSettings = expressCheckoutData?.data;
		window.awxExpressCheckoutSettings.checkout = awxCommonData.getExpressCheckoutData.checkout!;
		window.awxExpressCheckoutSettings.checkout.allowedCardNetworks = expressCheckoutData?.data?.allowedCardNetworks;

		if (expressCheckoutData?.data.isShowButtonOnProductPage && awxCommonData.getExpressCheckoutData.isProductPage) {
			$('.awx-ec-mini-cart-container').remove();
		}
		Airwallex!.init({
			env: awxExpressCheckoutSettings.env,
			origin: window.location.origin,
			locale: awxExpressCheckoutSettings.locale,
		} as Parameters<NonNullable<typeof Airwallex>['init']>[0]);

		renderExpressCheckoutByEvent();

		// refresh payment data when total is updated.
		$(document.body).on('updated_cart_totals', function () {
			renderExpressCheckoutByEvent(true);
		});

		// Checkout page only: skip rebuild when the order total is unchanged.
		$(document.body).on('updated_checkout', function () {
			renderExpressCheckoutByEvent();
		});

		$(document.body).on('change', '[name="quantity"]', function () {
			renderExpressCheckoutByEvent(true);
		});

		if (typeof window.awxMiniCartEnabled !== "undefined" && awxMiniCartEnabled === true) {
			const renderExpressCheckoutInBlockMiniCart = function (): void {
				const $footerActions = $('.wc-block-mini-cart__footer-actions');

				if ($footerActions.length === 0) {
					return;
				}

				if (typeof awxMiniCartConfig === 'undefined') {
					return;
				}

				const $existingButtonsRow = $footerActions.find('.awx-mini-cart-buttons-row');

				let $buttons: JQuery;
				if ($existingButtonsRow.length > 0) {
					$buttons = $existingButtonsRow.children();
					$existingButtonsRow.remove();
				} else {
					$buttons = $footerActions.children();
				}

				$.get(awxMiniCartConfig.templateUrl).then(html => {
					const $template = $(html);

					$footerActions.empty();
					$footerActions.append($template);
					$footerActions.find('.awx-mini-cart-buttons-row').append($buttons);

					renderExpressCheckoutByEvent(true);
				});
			};

			$(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart wc-blocks_removed_from_cart wc-blocks_added_to_cart', function () {
				renderExpressCheckoutByEvent(true);
			});
			renderExpressCheckoutInBlockMiniCart();
		}
	});
});
