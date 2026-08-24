import { useEffect, useRef, useState } from '@wordpress/element';
import { loadAirwallex } from 'airwallex-payment-elements';
import type { GooglePayButtonOptions, InitOptions } from 'airwallex-payment-elements';
import { getSetting } from '@woocommerce/settings';
import GooglePayButton from '@google-pay/button-react';
import { AIRWALLEX_MERCHANT_ID } from './constants';
import {
	createOrder,
	updateShippingOptions,
	updateShippingDetails,
} from './api'
import {
	maskPageWhileLoading,
	removePageMask,
	getFormattedValueFromBlockAmount,
	getGoogleFormattedShippingOptions,
	processError,
	getAllowedCardNetworks,
} from './utils';
import {
	getSupportedNetworksForGooglePay,
} from '../utils';
import {
	createElement as airwallexCreateElement,
	destroyElement,
} from 'airwallex-payment-elements';

import type {
	PlaceOrderResponse,
	ShippingResponse,
} from '../../types/api';
import type { AllowedCardNetworks } from '../../types/config';

/**
 * Minimal shape of the Google Pay element returned by the SDK's
 * `createElement('googlePayButton', ...)`. The full SDK type
 * `GooglePayButtonElementType` is broader; this local alias captures
 * just the surface this file uses so call sites stay typed without
 * a cross-package interop layer (same pattern as the apple-pay file).
 */
interface GooglePayElementLike {
	mount(id: string): unknown;
	on(event: string, handler: (e: Event) => void): void;
	update(options: Record<string, unknown>): void;
	confirmIntent(data: { client_secret: string; payment_consent?: Record<string, string> }): {
		then(cb: () => void): { catch(cb: (e: unknown) => void): unknown };
	};
}

/**
 * `CustomEvent.detail` payloads dispatched by the Google Pay SDK
 * element for the four events this file subscribes to. The runtime
 * wraps Google's native `IntermediatePaymentData` /
 * `PaymentData` payloads.
 */
interface GoogleShippingAddressChangeDetail {
	intermediatePaymentData: google.payments.api.IntermediatePaymentData;
}
interface GoogleShippingMethodChangeDetail {
	intermediatePaymentData: google.payments.api.IntermediatePaymentData;
}
interface GoogleAuthorizedDetail {
	paymentData: google.payments.api.PaymentData;
}
interface AwxElementErrorDetail { error: { detail?: string; message?: string } }

/**
 * Locally cached shipping options after a successful
 * `updateShippingOptions(...)` round-trip. Keyed by the Google Pay
 * SDK's expected shape for `update()`.
 */
interface AwxGoogleShippingOptions {
	shippingMethods: string[];
	shippingOptions: ReturnType<typeof getGoogleFormattedShippingOptions>;
}

interface GooglePaySettings {
	checkout?: {
		totalPriceLabel?: string;
		countryCode?: string;
		currencyCode?: string;
		isVirtualPurchase?: boolean;
		isSkipBillingForVirtual?: boolean;
		autoCapture?: boolean;
		requiresPhone?: boolean;
		[extra: string]: unknown;
	};
	button?: { theme?: string; buttonType?: string; height?: string };
	merchantInfo?: { businessName?: string; accountId?: string };
	transactionId?: string;
	locale?: string;
	env?: string;
	googlePayEnabled?: boolean;
	supports?: string[];
	[extra: string]: unknown;
}

const settings: GooglePaySettings = getSetting<GooglePaySettings>('airwallex_express_checkout_data', {});
settings.checkout = awxCommonData.getExpressCheckoutData.checkout as GooglePaySettings['checkout'];

const paymentMode = awxCommonData.getExpressCheckoutData.hasSubscriptionProduct ? 'recurring' : 'oneoff';

interface CartDetails {
	orderInfo: {
		total: { amount?: number };
		displayItems: google.payments.api.DisplayItem[] | unknown;
	};
	currencyCode?: string;
	countryCode?: string;
}

const getGoogleTransactionInfo = (cartDetails: CartDetails) => {
	const { checkout, transactionId } = settings;

	return {
		amount: {
			value: cartDetails.orderInfo.total.amount || 0,
			currency: cartDetails.currencyCode || checkout!.currencyCode,
		},
		transactionId: transactionId,
		totalPriceLabel: checkout!.totalPriceLabel,
		countryCode: cartDetails.countryCode || checkout!.countryCode,
		displayItems: cartDetails.orderInfo.displayItems,
	};
};

interface BillingShape {
	cartTotal: { value: number };
	currency: { code: string; minorUnit: number };
}

interface ShippingDataShape { needsShipping: boolean }

const getFormattedCartDetails = (billing: BillingShape) => {
	const { checkout, transactionId } = settings;

	return {
		amount: {
			value: getFormattedValueFromBlockAmount(billing.cartTotal.value, billing.currency.minorUnit) || 0,
			currency: billing.currency.code || checkout!.currencyCode,
		},
		transactionId: transactionId,
		totalPriceLabel: checkout!.totalPriceLabel,
		countryCode: checkout!.countryCode,
		displayItems: [],
	};
};

const getGooglePayRequestOptions = (
	billing: BillingShape,
	shippingData: ShippingDataShape,
	allowedCardNetworks: AllowedCardNetworks,
) => {
	const { button, checkout, merchantInfo } = settings;
	let paymentDataRequest: Record<string, unknown> = {
		mode: paymentMode,
		buttonColor: button!.theme,
		buttonType: button!.buttonType,
		emailRequired: true,
		billingAddressRequired: !(checkout!.isVirtualPurchase && checkout!.isSkipBillingForVirtual),
		billingAddressParameters: {
			format: 'FULL',
			phoneNumberRequired: checkout!.requiresPhone
		},
		merchantInfo: {
			merchantName: merchantInfo!.businessName,
		},
		autoCapture: checkout!.autoCapture,
		allowedCardNetworks: getSupportedNetworksForGooglePay(allowedCardNetworks.googlepay[paymentMode])
	};

	const callbackIntents = ['PAYMENT_AUTHORIZATION'];
	if (shippingData.needsShipping) {
		callbackIntents.push('SHIPPING_ADDRESS', 'SHIPPING_OPTION');
		paymentDataRequest.shippingAddressRequired = true;
		paymentDataRequest.shippingOptionRequired = true;
		paymentDataRequest.shippingAddressParameters = {
			phoneNumberRequired: checkout!.requiresPhone,
		};
	}
	paymentDataRequest.callbackIntents = callbackIntents;
	const transactionInfo = getFormattedCartDetails(billing);
	paymentDataRequest = Object.assign(paymentDataRequest, transactionInfo);

	return paymentDataRequest;
};

// `billing`, `shippingData`, and `onError` are injected by WC blocks at
// runtime via cloneElement; the JSX site (`<AWXGooglePayButton />`) has
// no props, so all fields are optional at compile time.
interface GooglePayProps {
	billing?: BillingShape;
	shippingData?: ShippingDataShape;
	onError?: (msg: string) => void;
	setExpressPaymentError?: (msg: string) => void;
}

const AWXGooglePayButton = (props: GooglePayProps) => {
	const {
		shippingData,
		billing,
		onError,
	} = props as Required<GooglePayProps>;

	let awxShippingOptions: AwxGoogleShippingOptions | [] = {} as AwxGoogleShippingOptions;
	const ELEMENT_TYPE = 'googlePayButton';
	// `element` is set but never read; retained for parity with the
	// original .jsx render shape.
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	const [element, setElement] = useState<unknown>();
	const [allowedCardNetworks, setAllowedCardNetworks] = useState<AllowedCardNetworks | null>(null);
	const elementRef = useRef<GooglePayElementLike | null>(null);

	const onShippingAddressChanged = async (event: Event) => {
		const { intermediatePaymentData } = (event as CustomEvent<GoogleShippingAddressChangeDetail>).detail;
		const { shippingAddress } = intermediatePaymentData;

		let paymentDataRequestUpdate: Record<string, unknown> = {};
		const response = (await updateShippingOptions({
			countryCode: shippingAddress?.countryCode,
			administrativeArea: shippingAddress?.administrativeArea,
			postalCode: shippingAddress?.postalCode,
			locality: shippingAddress?.locality,
		})) as unknown as ShippingResponse;
		if (response && response.success && response.shipping) {
			awxShippingOptions = {
				shippingMethods: response.shipping.shippingMethods,
				shippingOptions: getGoogleFormattedShippingOptions(response.shipping.shippingOptions),
			};
			paymentDataRequestUpdate.shippingOptionParameters = {
				defaultSelectedOptionId: awxShippingOptions.shippingMethods[0],
				shippingOptions: awxShippingOptions.shippingOptions
			};
			paymentDataRequestUpdate = Object.assign(paymentDataRequestUpdate, getGoogleTransactionInfo(response.cart as unknown as CartDetails))
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

	const onShippingMethodChanged = async (event: Event) => {
		const { intermediatePaymentData } = (event as CustomEvent<GoogleShippingMethodChangeDetail>).detail;
		const { shippingOptionData } = intermediatePaymentData;

		let paymentDataRequestUpdate: Record<string, unknown> = {};
		const shippingMethods = Array.isArray(awxShippingOptions) ? [] : awxShippingOptions.shippingMethods;
		const response = (await updateShippingDetails(shippingOptionData!.id, shippingMethods)) as unknown as ShippingResponse;
		if (response && response.success) {
			paymentDataRequestUpdate = getGoogleTransactionInfo(response.cart as unknown as CartDetails);
		} else {
			paymentDataRequestUpdate.error = {
				reason: 'SHIPPING_OPTION_INVALID',
				message: response.message,
				intent: 'SHIPPING_OPTION'
			};
		}
		elementRef.current?.update(paymentDataRequestUpdate);
	};

	const onAuthorized = async (event: Event) => {
		const { paymentData } = (event as CustomEvent<GoogleAuthorizedDetail>).detail;
		const orderResponse = (await createOrder(paymentData, 'googlepay')) as unknown as PlaceOrderResponse;
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
			} = orderResponse.payload!;

			if (createConsent) {
				elementRef.current?.confirmIntent({
					client_secret: clientSecret,
					payment_consent: {
						'next_triggered_by': 'merchant',
						'merchant_trigger_reason': 'scheduled',
					}
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error: unknown) => {
					processError(orderResponse, error as { message?: string }, removePageMask, onError);
				});
			} else {
				elementRef.current?.confirmIntent({
					client_secret: clientSecret,
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error: unknown) => {
					processError(orderResponse, error as { message?: string }, removePageMask, onError);
				});
			}
		} else {
			onError(orderResponse.messages ?? '');
			console.warn(orderResponse.messages);
			removePageMask();
			// temporary solution here to stop the developer error
			elementRef.current?.confirmIntent({
				client_secret: '',
			}).then(() => {
				// do nothing here
			}).catch((error: unknown) => {
				console.warn(error);
			});
		}
	};

	const onAWXError = (event: Event) => {
		const { error } = (event as CustomEvent<AwxElementErrorDetail>).detail;
		onError(error.detail ?? error.message ?? '');
		console.warn('There was an error', error);
	}

	const createGooglePayButton = () => {
		if (!allowedCardNetworks) return;

		// The literal built by `getGooglePayRequestOptions` includes
		// fields the SDK's `GooglePayButtonOptions` doesn't model
		// (`callbackIntents`, `shippingAddressParameters`,
		// `transactionId`, `displayItems`, etc.); cast through
		// `unknown` so the literal stays verbatim.
		const element = airwallexCreateElement(
			ELEMENT_TYPE,
			getGooglePayRequestOptions(billing, shippingData, allowedCardNetworks) as unknown as GooglePayButtonOptions,
		) as unknown as GooglePayElementLike | null;
		const googlePayElement = element?.mount('awxGooglePayButton');
		setElement(googlePayElement);
		elementRef.current = element;

		elementRef.current?.on('shippingAddressChange', (event: Event) => {
			onShippingAddressChanged(event);
		});

		elementRef.current?.on('shippingMethodChange', (event: Event) => {
			onShippingMethodChanged(event);
		});

		elementRef.current?.on('authorized', (event: Event) => {
			onAuthorized(event);
		});

		elementRef.current?.on('error', (event: Event) => {
			onAWXError(event);
		});
	};

	useEffect(() => {
		const initializeGooglePay = async () => {
			const options: InitOptions = {
				env: awxCommonData.env as InitOptions['env'],
				locale: awxCommonData.locale as InitOptions['locale'],
				origin: window.location.origin,
			};

			await loadAirwallex(options);
			Airwallex!.init(options);

			const networks = (await getAllowedCardNetworks()) as AllowedCardNetworks;
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
					gatewayMerchantId: merchantInfo!.accountId || '',
				},
			}
		}],
		merchantInfo: {
			merchantId: AIRWALLEX_MERCHANT_ID,
			merchantName: merchantInfo!.businessName,
		},
		transactionInfo: {
			totalPriceStatus: 'FINAL',
			totalPriceLabel: checkout!.totalPriceLabel,
			totalPrice: '0.00',
			currencyCode: checkout!.currencyCode,
			countryCode: checkout!.countryCode,
			displayItems: [],
		},
	};

	const gPayBtnProps = {
		buttonLocale: locale,
		environment: 'TEST',
		buttonSizeMode: 'fill',
		buttonColor: button!.theme,
		buttonType: button!.buttonType,
		style: {
			width: '100%',
			height: button!.height
		},
		paymentRequest: paymentDataRequest,
		onClick: (e: React.MouseEvent) => { e.preventDefault() },
	};
	return (
		<>
			<GooglePayButton
				// The literal above mixes loose `string | undefined`
				// values with `GooglePayButton`'s strict
				// `Environment` / `ButtonColor` / `ButtonType` unions,
				// and the SDK option `gateway: 'airwallex'` is broader
				// than Google's recognised gateways. Cast through
				// `unknown` so the runtime payload stays verbatim.
				{...(gPayBtnProps as unknown as React.ComponentProps<typeof GooglePayButton>)}
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
