import { useEffect, useRef, useState } from '@wordpress/element';
import { loadAirwallex } from 'airwallex-payment-elements';
import type { ApplePayButtonOptions, InitOptions } from 'airwallex-payment-elements';
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
	processError,
	getAllowedCardNetworks,
} from './utils';
import {
	getSupportedNetworksForApplePay,
} from '../utils';
import {
	createElement as airwallexCreateElement,
	destroyElement,
} from 'airwallex-payment-elements';

import { getSetting } from '@woocommerce/settings';
import type {
	MerchantSession,
	PlaceOrderResponse,
	ShippingResponse,
} from '../../types/api';

interface ApplePaySettings {
	checkout?: {
		totalPriceLabel?: string;
		countryCode?: string;
		currencyCode?: string;
		isVirtualPurchase?: boolean;
		isSkipBillingForVirtual?: boolean;
		autoCapture?: boolean;
		[extra: string]: unknown;
	};
	button?: { theme?: string; buttonType?: string; height?: string };
	isProductPage?: boolean;
	isCheckout?: boolean;
	applePayEnabled?: boolean;
	supports?: string[];
	[extra: string]: unknown;
}

/**
 * Per-payment-method allowed card networks, as returned by
 * `getAllowedCardNetworks()` (read from
 * `awxCommonData.getExpressCheckoutData.allowedCardNetworks`).
 */
interface AllowedCardNetworks {
	applepay: { oneoff: string[]; recurring: string[] };
	googlepay: { oneoff: string[]; recurring: string[] };
}

/**
 * Minimal shape of the Apple Pay element returned by the SDK's
 * `createElement('applePayButton', ...)`. The full SDK type
 * `ApplePayButtonElementType` is technically broader; we capture
 * just the surface this file uses to keep the call sites typed
 * without a cross-package interop layer.
 */
interface ApplePayElementLike {
	mount(id: string): unknown;
	on(event: string, handler: (e: Event) => void): void;
	update(options: Record<string, unknown>): void;
	confirmIntent(data: { client_secret: string; payment_consent?: Record<string, string> }): {
		then(cb: () => void): { catch(cb: (e: unknown) => void): unknown };
	};
	completeValidation(session: unknown): void;
	fail(error: unknown): void;
}

/**
 * `CustomEvent.detail` payloads dispatched by the Apple Pay SDK
 * element for the five events this file subscribes to.
 */
interface ValidateMerchantDetail { validationURL?: string }
interface ShippingAddressChangeDetail {
	shippingAddress?: {
		countryCode?: string;
		administrativeArea?: string;
		postalCode?: string;
		locality?: string;
	};
}
interface ShippingMethodChangeDetail { shippingMethod: { identifier: string } }
interface AuthorizedDetail {
	paymentData?: ApplePayJS.ApplePayPayment & { shippingMethods?: unknown };
}
interface AwxElementErrorDetail { error: { detail?: string; message?: string } }

const settings: ApplePaySettings = getSetting<ApplePaySettings>('airwallex_express_checkout_data', {});
settings.checkout = awxCommonData.getExpressCheckoutData.checkout as ApplePaySettings['checkout'];

const paymentMode = awxCommonData.getExpressCheckoutData.hasSubscriptionProduct ? 'recurring' : 'oneoff';
const isCheckoutPage = Boolean(settings.isCheckout ?? awxCommonData.getExpressCheckoutData?.isCheckout);

interface CartTotalItem { label: string; value: number }

const getAppleFormattedLineItemsFromCart = (cartTotalItems: CartTotalItem[], currencyMinorUnit: number) => {
	return cartTotalItems.map((item) => {
		return {
			label: item.label,
			amount: getFormattedValueFromBlockAmount(item.value, currencyMinorUnit),
		};
	});
}

interface BillingShape {
	cartTotal: { value: number };
	currency: { code: string; minorUnit: number };
	cartTotalItems: CartTotalItem[];
}

interface ShippingDataShape { needsShipping: boolean }

const getApplePayCurrency = (
	cart?: { currencyCode?: string },
	billingOverride?: BillingShape,
) => cart?.currencyCode || billingOverride?.currency?.code || settings.checkout?.currencyCode || '';

const getApplePayRequestOptions = (
	billing: BillingShape,
	shippingData: ShippingDataShape,
	allowedCardNetworks: AllowedCardNetworks,
) => {
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
		buttonColor: button!.theme,
		buttonType: button!.buttonType,
		origin: window.location.origin,
		totalPriceLabel: checkout!.totalPriceLabel,
		countryCode: checkout!.countryCode,
		requiredBillingContactFields: (checkout!.isVirtualPurchase && checkout!.isSkipBillingForVirtual) ? applePayRequiredBillingContactFields.filter(item => item !== 'postalAddress') : applePayRequiredBillingContactFields,
		requiredShippingContactFields: applePayRequiredShippingContactFields(needsShipping),
		amount: {
			value: cartTotal.value ? getFormattedValueFromBlockAmount(cartTotal.value, currency.minorUnit) : 0,
			currency: getApplePayCurrency(undefined, billing),
		},
		lineItems: getAppleFormattedLineItemsFromCart(cartTotalItems, currency.minorUnit),
		autoCapture: checkout!.autoCapture,
		supportedNetworks: getSupportedNetworksForApplePay(allowedCardNetworks.applepay[paymentMode])
	};
};

// `billing`, `shippingData`, and `onError` are injected by WC blocks at
// runtime via cloneElement; the JSX site (`<AWXApplePayButton />`) has
// no props, so all fields are optional at compile time.
interface ApplePayProps {
	billing?: BillingShape;
	shippingData?: ShippingDataShape;
	onError?: (msg: string) => void;
}

// `addToCart` is referenced in the original .jsx but never imported -
// it was a dead reference that triggered a runtime ReferenceError on
// the (untested) product-page branch. Preserve verbatim by declaring
// it ambiently and asserting at the call site.
declare const addToCart: () => Promise<unknown>;

const AWXApplePayButton = (props: ApplePayProps) => {
	const {
		checkout,
		isProductPage,
	} = settings;
	const {
		shippingData,
		billing,
		onError,
	} = props as Required<ApplePayProps>;

	let shippingMethods: string[] | Record<string, unknown> = {};
	const ELEMENT_TYPE = 'applePayButton';
	// `element` is set but never read; retained for parity with the
	// original .jsx render shape.
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	const [element, setElement] = useState<unknown>();
	const [allowedCardNetworks, setAllowedCardNetworks] = useState<AllowedCardNetworks | null>(null);
	const elementRef = useRef<ApplePayElementLike | null>(null);

	const onValidateMerchant = async (event: Event) => {
		const detail = (event as CustomEvent<ValidateMerchantDetail>).detail;
		if (isProductPage) await addToCart();
		const merchantSession = (await startPaymentSession(detail?.validationURL ?? '')) as unknown as MerchantSession;
		const { paymentSession, error } = merchantSession;

		if (paymentSession) {
			elementRef.current?.completeValidation(paymentSession);
		} else {
			elementRef.current?.fail(error);
		}
	};

	const onShippingAddressChanged = async (event: Event) => {
		const detail = (event as CustomEvent<ShippingAddressChangeDetail>).detail;
		if (shippingData.needsShipping) {
			const response = (await updateShippingOptions(detail?.shippingAddress ?? {})) as unknown as ShippingResponse;
			if (response && response.success) {
				const { shipping, cart } = response;
				shippingMethods = shipping?.shippingMethods ?? [];
				elementRef.current?.update({
					amount: {
						value: cart?.orderInfo?.total?.amount || 0,
						currency: getApplePayCurrency(cart, billing),
					},
					totalPriceLabel: checkout!.totalPriceLabel,
					lineItems: getAppleFormattedLineItems(cart?.orderInfo?.displayItems ?? []),
					shippingMethods: getAppleFormattedShippingOptions(shipping?.shippingOptions ?? []),
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
					currency: getApplePayCurrency(undefined, billing),
				},
				totalPriceLabel: checkout!.totalPriceLabel,
				lineItems: getAppleFormattedLineItemsFromCart(billing.cartTotalItems, billing.currency.minorUnit),
			});
		}

	}

	const onShippingMethodChanged = async (event: Event) => {
		const detail = (event as CustomEvent<ShippingMethodChangeDetail>).detail;
		const response = (await updateShippingDetails(detail.shippingMethod.identifier, shippingMethods as string[])) as unknown as ShippingResponse;
		if (response && response.success) {
			const { cart } = response;
			elementRef.current?.update({
				amount: {
					value: cart?.orderInfo?.total?.amount || 0,
					currency: getApplePayCurrency(cart, billing),
				},
				totalPriceLabel: checkout!.totalPriceLabel,
				lineItems: getAppleFormattedLineItems(cart?.orderInfo?.displayItems ?? []),
			});
		} else {
			console.warn(response.message);
			elementRef.current?.fail({
				message: response.message,
			});
		}
	};

	const onAuthorized = async (event: Event) => {
		const detail = (event as CustomEvent<AuthorizedDetail>).detail;
		const payment = (detail?.paymentData || {}) as ApplePayJS.ApplePayPayment & {
			shippingMethods?: typeof shippingMethods;
		};
		payment.shippingMethods = shippingMethods;
		const order = (await createOrder(payment, 'applepay')) as unknown as PlaceOrderResponse;
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
			} = order.payload!;

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
					processError(order, error as { message?: string }, removePageMask, onError);
				});
			} else {
				elementRef.current?.confirmIntent({
					client_secret: clientSecret,
				}).then(() => {
					location.href = confirmationUrl;
				}).catch((error: unknown) => {
					processError(order, error as { message?: string }, removePageMask, onError);
				});
			}
		} else {
			onError(order.messages ?? '');
			console.warn(order.messages);
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

	const createApplePayButton = () => {
		if (!allowedCardNetworks) return;

		// Two SDK gaps the runtime accepts:
		// 1. `origin` is set on the options but isn't on `ApplePayButtonOptions`.
		// 2. `requiredBillingContactFields` / `requiredShippingContactFields`
		//    are produced by `../utils.ts` helpers as `string[]`, but the SDK
		//    types them as `ApplePayJS.ApplePayContactField[]`. Bridge through
		//    `unknown` so the literal stays verbatim.
		const element = airwallexCreateElement(
			ELEMENT_TYPE,
			getApplePayRequestOptions(billing, shippingData, allowedCardNetworks) as unknown as ApplePayButtonOptions,
		) as unknown as ApplePayElementLike | null;
		const applePayElement = element?.mount('awxApplePayButton');
		setElement(applePayElement);
		elementRef.current = element;

		elementRef.current?.on('validateMerchant', (event: Event) => {
			onValidateMerchant(event);
		});

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
		const initializeApplePay = async () => {
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

		initializeApplePay();
	}, []);

	useEffect(() => {
		if (allowedCardNetworks) {
			createApplePayButton();
		}
	}, [allowedCardNetworks]);

	useEffect(() => {
		if (!elementRef.current) return;

		destroyElement(ELEMENT_TYPE);
		createApplePayButton();
		// Checkout: rebuild only when the numeric total/currency changes.
		// Cart (and other pages) keep the previous object-identity behaviour.
	}, [isCheckoutPage ? `${billing.cartTotal.value}::${billing.currency.code}` : billing.cartTotal]);

	return (<div id='awxApplePayButton' />);
};

const AWXApplePayButtonPreview = () => {
	const {
		checkout,
		button,
	} = settings;

	useEffect(() => {
		if ('Airwallex' in window) {
			// Same SDK gaps as `createApplePayButton` above: `origin` is not
			// on `ApplePayButtonOptions`, and the contact-field helpers
			// produce wider `string[]` than `ApplePayJS.ApplePayContactField[]`.
			// In addition, the original code passes the function reference
			// `applePayRequiredShippingContactFields` (not its result) into
			// the SDK - preserved verbatim under the cast.
			const element = airwallexCreateElement('applePayButton', {
				mode: paymentMode,
				buttonColor: button!.theme,
				buttonType: button!.buttonType,
				origin: window.location.origin,
				totalPriceLabel: checkout!.totalPriceLabel,
				countryCode: checkout!.countryCode,
				requiredBillingContactFields: applePayRequiredBillingContactFields,
				requiredShippingContactFields: applePayRequiredShippingContactFields,
				amount: {
					value: 0,
					currency: checkout!.currencyCode,
				},
			} as unknown as ApplePayButtonOptions);
			element?.mount('awxApplePayButtonPreview');
		}
	}, []);

	return (<div id='awxApplePayButtonPreview' />);
};

export const airwallexApplePayOption = {
	name: 'airwallex_express_checkout_apple_pay',
	content: <AWXApplePayButton />,
	edit: <AWXApplePayButtonPreview />,
	canMakePayment: () => !!settings?.applePayEnabled,
	paymentMethodId: 'airwallex_express_checkout',
	supports: {
		features: settings?.supports ?? [],
	}
};
