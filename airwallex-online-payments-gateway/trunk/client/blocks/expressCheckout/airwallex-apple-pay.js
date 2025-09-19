import { useEffect, useRef, useState } from '@wordpress/element';
import {
	createOrder,
	startPaymentSession,
	updateShippingOptions,
	updateShippingDetails,
} from './api';
import {
	maskPageWhileLoading,
	removePageMask,
	applePayRequiredBillingContactFields,
	applePayRequiredShippingContactFields,
	getAppleFormattedShippingOptions,
	getAppleFormattedLineItems,
	getFormattedValueFromBlockAmount,
} from './utils.js';
import {
	createElement as airwallexCreateElement,
	destroyElement,
} from 'airwallex-payment-elements';

import { getSetting } from '@woocommerce/settings';

const settings = getSetting('airwallex_express_checkout_data', {});
settings.checkout = awxCommonData.getExpressCheckoutData.checkout;
const paymentMode = awxCommonData.getExpressCheckoutData.hasSubscriptionProduct ? 'recurring' : 'oneoff';


const getAppleFormattedLineItemsFromCart = (cartTotalItems, currencyMinorUnit) => {
	return cartTotalItems.map((item) => {
		return {
			label: item.label,
			amount: getFormattedValueFromBlockAmount(item.value, currencyMinorUnit),
		};
	});
}

const getApplePayRequestOptions = (billing, shippingData) => {
	const {
		cartTotal,
		currency,
		cartTotalItems,
	} = billing;

	const {
		button,
		checkout
	} = settings;

	const {
		needsShipping,
	} = shippingData;

	return {
		mode: paymentMode,
		buttonColor: button.theme,
		buttonType: button.buttonType,
		origin: window.location.origin,
		totalPriceLabel: checkout.totalPriceLabel,
		countryCode: checkout.countryCode,
		requiredBillingContactFields: applePayRequiredBillingContactFields,
		requiredShippingContactFields: applePayRequiredShippingContactFields(needsShipping),
		amount: {
			value: cartTotal.value ? getFormattedValueFromBlockAmount(cartTotal.value, currency.minorUnit) : 0,
			currency: currency.code ? currency.code  : checkout.currencyCode,
		},
		lineItems: getAppleFormattedLineItemsFromCart(cartTotalItems, currency.minorUnit),
		autoCapture: checkout.autoCapture,
	};
};

const AWXApplePayButton = (props) => {
	const {
		checkout,
		isProductPage,
	} = settings;
	const {
		shippingData,
		billing,
		onError,
	} = props;

	let shippingMethods = {};
	const ELEMENT_TYPE = 'applePayButton';
	const [element, setElement] = useState();
	const elementRef = useRef(null);

	const onValidateMerchant = async (event) => {
		if (isProductPage) await addToCart();
		const merchantSession = await startPaymentSession(event?.detail?.validationURL);
		const { paymentSession, error } = merchantSession;

		if (paymentSession) {
			elementRef.current?.completeValidation(paymentSession);
		} else {
			elementRef.current?.fail(error);
		}
	};

	const onShippingAddressChanged = async (event) => {
		if (shippingData.needsShipping) {
			const response = await updateShippingOptions(event?.detail?.shippingAddress);
			if (response && response.success) {
				const { shipping, cart } = response;
				shippingMethods = shipping.shippingMethods;
				elementRef.current?.update({
					amount: {
						value: cart?.orderInfo?.total?.amount || 0,
					},
					totalPriceLabel: checkout.totalPriceLabel,
					lineItems: getAppleFormattedLineItems(cart.orderInfo.displayItems),
					shippingMethods: getAppleFormattedShippingOptions(shipping.shippingOptions),
				});
			} else {
				shippingMethods = [];
				console.warn(response.message);
				elementRef.current?.fail({
					message: response.message,
				});
			}
		} else {
			elementRef.current?.update({
				amount: {
					value: billing?.cartTotal?.value ? getFormattedValueFromBlockAmount(billing?.cartTotal?.value, billing.currency.minorUnit) : 0,
				},
				totalPriceLabel: checkout.totalPriceLabel,
				lineItems: getAppleFormattedLineItemsFromCart(billing.cartTotalItems, billing.currency.minorUnit),
			});
		}
		
	}

	const onShippingMethodChanged = async (event) => {
		const response = await updateShippingDetails(event.detail.shippingMethod.identifier, shippingMethods);
		if (response && response.success) {
			const { cart } = response;
			elementRef.current?.update({
				amount: {
					value: cart?.orderInfo?.total?.amount || 0,
				},
				totalPriceLabel: checkout.totalPriceLabel,
				lineItems: getAppleFormattedLineItems(cart.orderInfo.displayItems),
			});
		} else {
			console.warn(response.message);
			elementRef.current?.fail({
				message: response.message,
			});
		}
	};

	const onAuthorized = async (event) => {
		let payment = event?.detail?.paymentData || {};
		payment['shippingMethods'] = shippingMethods;
		const order = await createOrder(payment, 'applepay');
		if (order.redirect_url) {
			location.href = order.redirect_url;
			return;
		}
		maskPageWhileLoading(50000);
		if (order.result === 'success') {
			const {
				createConsent,
				clientSecret,
				confirmationUrl,
			} = order.payload;

			if (createConsent) {
				elementRef.current?.createPaymentConsent({
					client_secret: clientSecret,
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error) => {
					removePageMask();
					onError(error.message);
					console.warn(error.message);
				});
			} else {
				elementRef.current?.confirmIntent({
					client_secret: clientSecret,
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error) => {
					removePageMask();
					onError(error.message);
					console.warn(error.message);
				});
			}
		} else {
			onError(order.messages);
			console.warn(order.messages);
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

	const createApplePayButton = () => {
		const element = airwallexCreateElement(ELEMENT_TYPE, getApplePayRequestOptions(billing, shippingData));
		const applePayElement = element.mount('awxApplePayButton');
		setElement(applePayElement);
		elementRef.current = element;

		elementRef.current?.on('validateMerchant', (event) => {
			onValidateMerchant(event);
		});

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
		if ('Airwallex' in window) {
			createApplePayButton();
		}
	}, []);

	useEffect(() => {
		if (!elementRef.current) return;

		destroyElement(ELEMENT_TYPE);
		createApplePayButton();
	}, [billing.cartTotal]);

	return (<div id='awxApplePayButton' />);
};

const AWXApplePayButtonPreview = (props) => {
	const {
		checkout,
		locale,
		button,
	} = settings;

	useEffect(() => {
		if ('Airwallex' in window) {
			const element = airwallexCreateElement('applePayButton', {
				mode: paymentMode,
				buttonColor: button.theme,
				buttonType: button.buttonType,
				origin: window.location.origin,
				totalPriceLabel: checkout.totalPriceLabel,
				countryCode: checkout.countryCode,
				requiredBillingContactFields: applePayRequiredBillingContactFields,
				requiredShippingContactFields: applePayRequiredShippingContactFields,
				amount: {
					value: 0,
					currency: checkout.currencyCode,
				},
			});
			element.mount('awxApplePayButtonPreview');
		}
	}, []);

	return (<div id='awxApplePayButtonPreview' />);
};

const canMakePayment = ({
	cartTotals
}) => {
	const { button, checkout } = settings;

	return (cartTotals.total_price != '0'
		&& settings.applePayEnabled
		&& paymentMode in checkout.allowedCardNetworks.applepay
		&& checkout.allowedCardNetworks.applepay[paymentMode].length > 0
		) ?? false;
};

export const airwallexApplePayOption = {
	name: 'airwallex_express_checkout_apple_pay',
	content: <AWXApplePayButton />,
	edit: <AWXApplePayButtonPreview />,
	canMakePayment: canMakePayment,
	paymentMethodId: 'airwallex_express_checkout',
	supports: {
		features: settings?.supports ?? [],
	}
};
