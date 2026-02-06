import { useEffect, useRef, useState } from '@wordpress/element';
import { loadAirwallex } from 'airwallex-payment-elements';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import GooglePayButton from '@google-pay/button-react';
import { AIRWALLEX_MERCHANT_ID } from './constants.js';
import {
	createOrder,
	updateShippingOptions,
	updateShippingDetails,
} from './api.js'
import {
	maskPageWhileLoading,
	removePageMask,
	getFormattedValueFromBlockAmount,
	getGoogleFormattedShippingOptions,
	processError,
	getAllowedCardNetworks,
} from './utils.js';
import {
	getSupportedNetworksForGooglePay,
} from '../utils.js';
import {
	createElement as airwallexCreateElement,
	destroyElement,
} from 'airwallex-payment-elements';

const settings = getSetting('airwallex_express_checkout_data', {});
settings.checkout = awxCommonData.getExpressCheckoutData.checkout;

const paymentMode = awxCommonData.getExpressCheckoutData.hasSubscriptionProduct ? 'recurring' : 'oneoff';

const getGoogleTransactionInfo = (cartDetails) => {
	const { checkout, transactionId } = settings;

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
};

const getFormattedCartDetails = (billing) => {
	const { checkout, transactionId } = settings;

	return {
		amount: {
			value: getFormattedValueFromBlockAmount(billing.cartTotal.value, billing.currency.minorUnit) || 0,
			currency: billing.currency.code || checkout.currencyCode,
		},
		transactionId: transactionId,
		totalPriceLabel: checkout.totalPriceLabel,
		countryCode: checkout.countryCode,
		displayItems: [],
	};
};

const getGooglePayRequestOptions = (billing, shippingData, allowedCardNetworks) => {
	const { button, checkout, merchantInfo } = settings;
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
		allowedCardNetworks: getSupportedNetworksForGooglePay(allowedCardNetworks.googlepay[paymentMode])
	};

	let callbackIntents = ['PAYMENT_AUTHORIZATION'];
	if (shippingData.needsShipping) {
		callbackIntents.push('SHIPPING_ADDRESS', 'SHIPPING_OPTION');
		paymentDataRequest.shippingAddressRequired = true;
		paymentDataRequest.shippingOptionRequired = true;
		paymentDataRequest.shippingAddressParameters = {
			phoneNumberRequired: checkout.requiresPhone,
		};
	}
	paymentDataRequest.callbackIntents = callbackIntents;
	const transactionInfo = getFormattedCartDetails(billing);
	paymentDataRequest = Object.assign(paymentDataRequest, transactionInfo);

	return paymentDataRequest;
};

const AWXGooglePayButton = (props) => {
	const {
		locale,
		env,
	} = settings;
	const {
		setExpressPaymentError,
		shippingData,
		billing,
		onError,
	} = props;

	let awxShippingOptions = {};
	const ELEMENT_TYPE = 'googlePayButton';
	const [element, setElement] = useState();
	const [allowedCardNetworks, setAllowedCardNetworks] = useState(null);
	const elementRef = useRef(null);

	const onShippingAddressChanged = async (event) => {

		const { shippingAddress } = event.detail.intermediatePaymentData;

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
			paymentDataRequestUpdate = Object.assign(paymentDataRequestUpdate, getGoogleTransactionInfo(response['cart']))
		} else {
			awxShippingOptions = [];
			paymentDataRequestUpdate.error = {
				reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
				message: response.message,
				intent: 'SHIPPING_ADDRESS'
			};
		}
		elementRef.current?.update(paymentDataRequestUpdate);
	};

	const onShippingMethodChanged = async (event) => {
		const { shippingOptionData } = event.detail.intermediatePaymentData;

		let paymentDataRequestUpdate = {};
		const response = await updateShippingDetails(shippingOptionData.id, awxShippingOptions.shippingMethods);
		if (response && response.success) {
			paymentDataRequestUpdate = getGoogleTransactionInfo(response['cart']);
		} else {
			paymentDataRequestUpdate.error = {
				reason: 'SHIPPING_OPTION_INVALID',
				message: response.message,
				intent: 'SHIPPING_OPTION'
			};
		}
		elementRef.current?.update(paymentDataRequestUpdate);
	};

	const onAuthorized = async (event) => {
		const orderResponse = await createOrder(event.detail.paymentData, 'googlepay');
		if (orderResponse.redirect_url) {
			location.href = orderResponse.redirect_url;
			return;
		}
		maskPageWhileLoading(50000);
		if (orderResponse.result === 'success') {
			const {
				createConsent,
				clientSecret,
				confirmationUrl,
			} = orderResponse.payload;

			if (createConsent) {
				elementRef.current?.confirmIntent({
					client_secret: clientSecret,
					payment_consent: {
						'next_triggered_by': 'merchant',
						'merchant_trigger_reason': 'scheduled',
					}
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error) => {
					processError(orderResponse, error, removePageMask, onError);
				});
			} else {
				elementRef.current?.confirmIntent({
					client_secret: clientSecret,
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error) => {
					processError(orderResponse, error, removePageMask, onError);
				});
			}
		} else {
			onError(orderResponse.messages);
			console.warn(orderResponse.messages);
			removePageMask();
			// temporary solution here to stop the developer error
			elementRef.current?.confirmIntent({
				client_secret: '',
			}).then(() => {
				// do nothing here
			}).catch((error) => {
				console.warn(error);
			});
		}
	};

	const onAWXError = (event) => {
		const { error } = event.detail;
		onError(error.detail);
		console.warn('There was an error', error);
	}

	const createGooglePayButton = () => {
		if (!allowedCardNetworks) return;

		const element = airwallexCreateElement(ELEMENT_TYPE, getGooglePayRequestOptions(billing, shippingData, allowedCardNetworks));
		const googlePayElement = element.mount('awxGooglePayButton');
		setElement(googlePayElement);
		elementRef.current = element;

		elementRef.current?.on('shippingAddressChange', (event) => {
			onShippingAddressChanged(event);
		});

		elementRef.current?.on('shippingMethodChange', (event) => {
			onShippingMethodChanged(event);
		});

		elementRef.current?.on('authorized', (event) => {
			onAuthorized(event);
		});

		elementRef.current?.on('error',(event) => {
			onAWXError(event);
		});
	};

	useEffect(() => {
		const initializeGooglePay = async () => {
			let options = {
				env: awxCommonData.env,
				locale: awxCommonData.locale,
				origin: window.location.origin,
			};

			await loadAirwallex(options);
			Airwallex.init(options);

			const networks = await getAllowedCardNetworks();
			setAllowedCardNetworks(networks);
		};

		initializeGooglePay();
	}, []);

	useEffect(() => {
		if (allowedCardNetworks) {
			createGooglePayButton();
		}
	}, [allowedCardNetworks]);

	useEffect(() => {
		if (!elementRef.current) return;

		destroyElement(ELEMENT_TYPE);
		createGooglePayButton();
	}, [billing.cartTotal]);

	return (
		<div
			id="awxGooglePayButton"
		/>
	);
};

const AWXGooglePayButtonPreview = () => {
	const {
		checkout,
		locale,
		button,
		merchantInfo,
	} = settings;

	const paymentDataRequest = {
		apiVersion: 2,
		apiVersionMinor: 0,
		allowedPaymentMethods: [{
			type: 'CARD',
			parameters: {
				allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
				allowedCardNetworks: ["MASTERCARD", "VISA"],
			},
			tokenizationSpecification: {
				type: 'PAYMENT_GATEWAY',
				parameters: {
					gateway: 'airwallex',
					gatewayMerchantId: merchantInfo.accountId || '',
				},
			}
		}],
		merchantInfo: {
			merchantId: AIRWALLEX_MERCHANT_ID,
			merchantName: merchantInfo.businessName,
		},
		transactionInfo: {
			totalPriceStatus: 'FINAL',
			totalPriceLabel: checkout.totalPriceLabel,
			totalPrice: '0.00',
			currencyCode: checkout.currencyCode,
			countryCode: checkout.countryCode,
			displayItems: [],
		},
	};

	let gPayBtnProps = {
		buttonLocale: locale,
		environment: 'TEST',
		buttonSizeMode: 'fill',
		buttonColor: button.theme,
		buttonType: button.buttonType,
		style: {
			width: '100%',
			height: button.height
		},
		paymentRequest: paymentDataRequest,
		onClick: (e) => { e.preventDefault() },
	};
	return (
		<>
			<GooglePayButton
				{...gPayBtnProps}
			/>
		</>
	);
};

export const airwallexGooglePayOption = {
	name: 'airwallex_express_checkout_google_pay',
	content: <AWXGooglePayButton />,
	edit: <AWXGooglePayButtonPreview />,
	canMakePayment: () => !!settings?.googlePayEnabled,
	paymentMethodId: 'airwallex_express_checkout',
	supports: {
		features: settings?.supports ?? [],
	}
};
