import {
	addToCart,
	getCartDetails,
	updateShippingOptions,
	updateShippingDetails,
	createOrder,
	startPaymentSession,
	getEstimatedCartDetails,
} from './api.js';
import {
	applePayRequiredBillingContactFields,
	applePayRequiredShippingContactFields,
	getAppleFormattedShippingOptions,
	getAppleFormattedLineItems,
	getGoogleFormattedShippingOptions,
	displayLoginConfirmation,
	maskPageWhileLoading,
	removePageMask,
	getSupportedNetworksForApplePay,
	getSupportedNetworksForGooglePay,
} from './utils.js';

/* global awxExpressCheckoutSettings, Airwallex */
jQuery(function($) {
	'use strict';

	const paymentMode = awxCommonData.getExpressCheckoutData.hasSubscriptionProduct ? 'recurring' : 'oneoff';
	let awxShippingOptions = [], shippingMethods = [];
	let globalCartDetails = {};

	let googlepay, applePay;
	let isExpressCheckoutRendering = false;

	const renderExpressCheckoutByEvent = function () {
		if (isExpressCheckoutRendering) {
			setTimeout(function(){
				isExpressCheckoutRendering = false;
			}, 1000)
			return;
		}
		isExpressCheckoutRendering = true;
		airwallexExpressCheckout.init();
	};

	const airwallexExpressCheckout = {
		init: async function () {
			// if settings are not available, do not proceed
			if (!('awxExpressCheckoutSettings' in window) || Object.keys(awxExpressCheckoutSettings).length === 0) {
				return;
			}

			// get cart details
			globalCartDetails = awxCommonData.getExpressCheckoutData.isProductPage ? await getEstimatedCartDetails() : await getCartDetails();

			const { button, checkout } = awxExpressCheckoutSettings;
			
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

		initGooglePayButton: async function() {
			const googlePayRequestOptions = await airwallexExpressCheckout.getGooglePayRequestOptions();
			googlepay = Airwallex.createElement('googlePayButton', googlePayRequestOptions);
			const domElement = googlepay.mount('awx-ec-google-pay-btn');

			googlepay.on('ready', (event) => {
				$('#awx-express-checkout-wrapper').show();
				$('.awx-google-pay-btn').show();
				$('#awx-express-checkout-button-separator').show();
				$('.awx-express-checkout-error').html('').hide();
			});

			googlepay.on('click', (event) => {
				$('.awx-express-checkout-error').html('').hide();
			});

			googlepay.on('shippingAddressChange', async (event) => {
				const { callbackTrigger, shippingAddress } = event.detail.intermediatePaymentData;

				// add product to the cart which is required for shipping calculation
				if (callbackTrigger == 'INITIALIZE' && awxCommonData.getExpressCheckoutData.isProductPage) {
					await addToCart();
				}

				let paymentDataRequestUpdate = {};
				const response = await updateShippingOptions(shippingAddress);
				if (response && response.success) {
					awxShippingOptions = {
						shippingMethods: response.shipping.shippingMethods,
						shippingOptions: getGoogleFormattedShippingOptions(response.shipping.shippingOptions),
					};
					paymentDataRequestUpdate.shippingOptionParameters = {
						defaultSelectedOptionId: awxShippingOptions.shippingMethods[0],
						shippingOptions: awxShippingOptions.shippingOptions
					};
					paymentDataRequestUpdate = Object.assign(paymentDataRequestUpdate,  airwallexExpressCheckout.getGoogleTransactionInfo(response['cart']))
				} else {
					awxShippingOptions = [];
					paymentDataRequestUpdate.error = {
						reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
						message: response.message,
						intent: 'SHIPPING_ADDRESS'
					};
				}
				googlepay.update(paymentDataRequestUpdate);
			});

			googlepay.on('shippingMethodChange', async (event) => {
				const { shippingOptionData } = event.detail.intermediatePaymentData;

				let paymentDataRequestUpdate = {};
				const response = await updateShippingDetails(shippingOptionData.id, awxShippingOptions.shippingMethods);
				if (response && response.success) {
					paymentDataRequestUpdate = airwallexExpressCheckout.getGoogleTransactionInfo(response['cart']);
				} else {
					paymentDataRequestUpdate.error = {
						reason: 'SHIPPING_OPTION_INVALID',
						message: response.message,
						intent: 'SHIPPING_OPTION'
					};
				}

				googlepay.update(paymentDataRequestUpdate);
			});

			googlepay.on('authorized', async (event) => {
				if (awxCommonData.getExpressCheckoutData.isProductPage) await addToCart();
				const order = await createOrder(event.detail.paymentData, 'googlepay');
				if (order.redirect_url) {
					location.href = order.redirect_url;
					return;
				}
				airwallexExpressCheckout.processPayment(googlepay, order);
			});

			googlepay.on('error', (event) => {
				console.error('There was an error', event);
			});
		},

		getGooglePayRequestOptions: async function() {
			const cartDetails = awxCommonData.getExpressCheckoutData.isProductPage ? await getEstimatedCartDetails() : await getCartDetails();
			const { button, checkout, merchantInfo, transactionId } = awxExpressCheckoutSettings;

			if (!cartDetails.success) {
				console.warn(response.message);
				return [];
			}

			let paymentDataRequest = {
				mode: paymentMode,
				buttonColor: button.theme,
				buttonType: button.buttonType,
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
				allowedCardNetworks: getSupportedNetworksForGooglePay(checkout.allowedCardNetworks.googlepay[paymentMode])
			};

			let callbackIntents = ['PAYMENT_AUTHORIZATION'];
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

		getGoogleTransactionInfo: function (cartDetails) {
			const { checkout, transactionId } = awxExpressCheckoutSettings;

			return {
				amount: {
					value: cartDetails.orderInfo.total.amount || 0,
					currency: cartDetails.currencyCode || checkout.currencyCode,
				},
				transactionId: transactionId,
				totalPriceLabel: checkout.totalPriceLabel,
				countryCode: cartDetails.countryCode || checkout.countryCode,
				displayItems: cartDetails.orderInfo.displayItems,
			};
		},

		initApplePayButton: () => {
			const { checkout } = awxExpressCheckoutSettings;
			const applePayRequestOptions = airwallexExpressCheckout.getApplePayRequestOptions(globalCartDetails);
			applePay = Airwallex.createElement('applePayButton', applePayRequestOptions);
			applePay.mount('awx-ec-apple-pay-btn');

			applePay.on('ready', (event) => {
				$('#awx-express-checkout-wrapper').show();
				$('.awx-apple-pay-btn').show();
				$('#awx-express-checkout-button-separator').show();
			});

			applePay.on('click', (event) => {
				$('.awx-express-checkout-error').html('').hide();
			});

			applePay.on('validateMerchant', async (event) => {
				if (awxCommonData.getExpressCheckoutData.isProductPage) await addToCart();
				const merchantSession = await startPaymentSession(event?.detail?.validationURL);
				const { paymentSession, error } = merchantSession;

				if (paymentSession) {
					applePay.completeValidation(paymentSession);
				} else {
					applePay.fail(error);
				}
			});

			applePay.on('shippingAddressChange', async (event) => {
				const cartDetails = await getCartDetails();
				if ( cartDetails.success) {
					if (cartDetails.requiresShipping) {
						const response = await updateShippingOptions(event?.detail?.shippingAddress);
						if (response && response.success) {
							const { shipping, cart } = response;
							shippingMethods = shipping.shippingMethods;
							applePay.update({
								amount: {
									value: cart?.orderInfo?.total?.amount || 0,
								},
								lineItems: getAppleFormattedLineItems(cart.orderInfo.displayItems),
								shippingMethods: getAppleFormattedShippingOptions(shipping.shippingOptions),
								totalPriceLabel: checkout.totalPriceLabel,
							});
						} else {
							shippingMethods = [];
							console.warn(response?.message);
							applePay.fail({
								message: response?.message,
							});
						}
					} else {
						applePay.update({
							amount: {
								value: cartDetails?.orderInfo?.total?.amount || 0,
							},
							lineItems: getAppleFormattedLineItems(cartDetails.orderInfo.displayItems),
							totalPriceLabel: checkout.totalPriceLabel,
						});
					}
				} else {
					console.warn(cartDetails.message);
					applePay.fail({
						message: cartDetails.message,
					});
				}
			});

			applePay.on('shippingMethodChange', async (event) => {
				const response = await updateShippingDetails(event.detail.shippingMethod.identifier, shippingMethods);
				if (response && response.success) {
					const { cart } = response;
					applePay.update({
						amount: {
							value: cart?.orderInfo?.total?.amount || 0,
						},
						lineItems: getAppleFormattedLineItems(cart.orderInfo.displayItems),
						totalPriceLabel: checkout.totalPriceLabel,
					});
				} else {
					console.warn(response.message);
					applePay.fail({
						message: response?.message,
					});
				}
			});

			applePay.on('authorized', async (event) => {
				let payment = event?.detail?.paymentData || {};
				payment['shippingMethods'] = shippingMethods;
				const order = await createOrder(payment, 'applepay');
				if (order.redirect_url) {
					location.href = order.redirect_url;
					return;
				}
				airwallexExpressCheckout.processPayment(applePay, order);
			});

			applePay.on('error', (event) => {
				console.error('There was an error', event);
			});
		},

		getApplePayRequestOptions: (cartDetails) => {
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

			return {
				mode: paymentMode,
				buttonColor: button.theme,
				buttonType: button.buttonType,
				origin: window.location.origin,
				totalPriceLabel: checkout.totalPriceLabel,
				countryCode: countryCode ? countryCode : checkout.countryCode,
				requiredBillingContactFields: (checkout.isVirtualPurchase && checkout.isSkipBillingForVirtual) ? applePayRequiredBillingContactFields.filter(item => item !== 'postalAddress') : applePayRequiredBillingContactFields,
				requiredShippingContactFields: applePayRequiredShippingContactFields(requiresShipping),
				amount: {
					value: orderInfo ? orderInfo.total.amount : checkout.subTotal,
					currency: currencyCode ? currencyCode : checkout.currencyCode,
				},
				lineItems: getAppleFormattedLineItems(orderInfo.displayItems),
				autoCapture: checkout.autoCapture,
				supportedNetworks: getSupportedNetworksForApplePay(checkout.allowedCardNetworks.applepay[paymentMode])
			};
		},

		processError(data, err) {
			$.ajax({
				url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + data.order_id,
				method: 'GET',
				dataType: 'json',
				success: function(response) {
					let errMessage = response.success ? (err.message || '') : response.message;
					removePageMask();
					$('.awx-express-checkout-error').html(errMessage).show();
					console.warn(errMessage);                 
				},
				error: function(xhr, status, error) {
					let errMessage = xhr.responseText;
					if (xhr.responseJSON && xhr.responseJSON.message) {
						errMessage = xhr.responseJSON.message;
					}
					removePageMask();
					$('.awx-express-checkout-error').html(errMessage).show();
					console.warn(errMessage);   
				}
			});
		},

		processPayment: (element, data) => {
			maskPageWhileLoading(50000);
			if (data.result === 'success') {
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
						location.href = confirmationUrl;
					}).catch((error) => {
						airwallexExpressCheckout.processError(data, error)
					});
				} else {
					element.confirmIntent({
						client_secret: clientSecret,
					}).then(() => {
						location.href = confirmationUrl;
					}).catch((error) => {
						airwallexExpressCheckout.processError(data, error)
					});
				}
			} else {
				removePageMask();
				$('.awx-express-checkout-error').html(data?.messages).show();
				console.warn(data);
				// temporary solution here to stop the developer error
				element.confirmIntent({
					client_secret: '',
				}).then(() => {
					// do nothing here
				}).catch((error) => {
					console.warn(error);
				});
			}
		},

		/**
		 * Change the height of the button according to settings
		 */
		setButtonHeight: function () {
			const { button } = awxExpressCheckoutSettings;
			const height     = button.height;
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
	}).done(function(expressCheckoutData) {
		window.awxExpressCheckoutSettings = expressCheckoutData?.data;
		window.awxExpressCheckoutSettings.checkout = awxCommonData.getExpressCheckoutData.checkout;
		window.awxExpressCheckoutSettings.checkout.allowedCardNetworks = expressCheckoutData?.data?.allowedCardNetworks;

		Airwallex.init({
			env: awxExpressCheckoutSettings.env,
			origin: window.location.origin,
			locale: awxExpressCheckoutSettings.locale,
		});

		renderExpressCheckoutByEvent();

		// refresh payment data when total is updated.
		$( document.body ).on( 'updated_cart_totals', function() {
			renderExpressCheckoutByEvent();
		} );

		// refresh payment data when total is updated.
		$( document.body ).on( 'updated_checkout', function() {
			renderExpressCheckoutByEvent();
		} );

		$(document.body).on('change', '[name="quantity"]', function () {
			renderExpressCheckoutByEvent();
		});
	});
});
