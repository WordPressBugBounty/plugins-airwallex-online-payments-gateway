import { useEffect, useRef, useState } from 'react';
import {
	loadAirwallex,
	createElement as createAirwallexElement,
	confirmPaymentIntent as confirmAirwallexPaymentIntent,
	createPaymentConsent as createAirwallexPaymentConsent,
	getElement as getAirwallexElement,
} from 'airwallex-payment-elements';
import type { InitOptions, InputStyle } from 'airwallex-payment-elements';
import { __ } from '@wordpress/i18n';
import { getCardHolderName, getBillingInformation, type BillingData } from '../utils';
import { updateOrderStatusAfterPaymentDecline } from '../api';
import type { PaymentMethodComponentProps } from '../../types/blocks';

/**
 * Minimal shape of the Airwallex CVC element this file consumes.
 * The `airwallex-payment-elements` ESM `CvcElementType` and the
 * globally-typed `Payment.CvcElementType` from `@airwallex/components-sdk`
 * have incompatible `on()` signatures, so this local alias keeps both
 * runtime sources assignable to `cvcElementRef.current` without an
 * `any` escape.
 */
interface CvcElementLike {
	confirm(data: unknown): Promise<unknown>;
	mount(id: string): unknown;
	on(event: string, handler: (e: Event) => void): void;
	destroy(): void;
}

/**
 * Helper alias: the parameter type accepted by the SDK's
 * `confirmPaymentIntent` and `createPaymentConsent`. Pulled from the
 * imported runtime functions so we don't have to name the SDK
 * interfaces (which are too strict for the fields the runtime
 * actually accepts here).
 */
type ConfirmPaymentIntentArg = NonNullable<Parameters<typeof confirmAirwallexPaymentIntent>[0]>;
type CreatePaymentConsentArg = NonNullable<Parameters<typeof createAirwallexPaymentConsent>[0]>;

interface CardSettings {
	confirm_url?: string;
	capture_immediately?: boolean;
	environment?: string;
	locale?: string;
	tokens?: Record<string, { is_hide_cvc_element?: boolean; type?: string }>;
	// `BlockMethodSettings` superset fields that the card-block factory
	// also passes through (`name`, `title`, `description`, `enabled`, ...).
	[extra: string]: unknown;
}

interface PaymentDetails {
	orderId?: string | number;
	paymentIntent?: string;
	clientSecret?: string;
	customerId?: string;
	paymentMethodId?: string;
	createConsent?: boolean;
	currency?: string;
}

/**
 * Shape of the WooCommerce Blocks `processingResponse` payload that is
 * passed to `onCheckoutSuccess` / `onCheckoutFail` callbacks. WC types
 * `processingResponse` loosely, so we narrow only the fields this
 * component reads (`paymentDetails.*`).
 */
interface ProcessingResponseArg {
	processingResponse?: {
		paymentDetails?: PaymentDetails & { errorMessage?: string };
	};
}

/**
 * Subset of the jQuery `jqXHR` reject value that `updateOrderStatus...`
 * exposes when the AJAX call fails. The catch handler only reads
 * `responseText` / `responseJSON.message`.
 */
interface AjaxErrorLike {
	responseText?: string;
	responseJSON?: { message?: string };
}

interface ConfirmPaymentArgs {
	settings: CardSettings;
	paymentDetails: PaymentDetails;
	cvcElementRef?: { current: CvcElementLike | null };
	billingData: BillingData;
	successType: string;
	errorType: string;
	errorContext: string;
}

interface PaymentResponse {
	type: string;
	confirmUrl?: string;
	code?: string;
	message?: string;
	messageContext?: string;
	orderId?: string | number;
}

const confirmPayment      = ({
	settings,
	paymentDetails,
	cvcElementRef,
	billingData,
	successType,
	errorType,
	errorContext,
}: ConfirmPaymentArgs): Promise<PaymentResponse> => {
	const airwallexSaveChecked = (document.getElementById('airwallex-save') as HTMLInputElement | null)?.checked;
	const confirmUrlBase = settings.confirm_url as string;
	const separator = confirmUrlBase.includes('?') ? '&' : '?';
	const confirmUrl = `${confirmUrlBase}${separator}order_id=${paymentDetails.orderId}&intent_id=${paymentDetails.paymentIntent}&is_airwallex_save_checked=${airwallexSaveChecked}`;

	const card            = getAirwallexElement('card');
	const paymentResponse: PaymentResponse = { type: successType };
	paymentResponse.confirmUrl = confirmUrl;

	let request: Promise<unknown>;
	if (paymentDetails.paymentMethodId) {
		// `cvcElementRef.current.confirm(data)` accepts `unknown` here -
		// the runtime ingests `payment_method_id`, top-level `billing`,
		// `payment_consent`, and `currency`, none of which the SDK's
		// `PaymentMethodRequestData` models. The literal stays verbatim.
		const confirmData: Record<string, unknown> = {
			client_secret: paymentDetails.clientSecret,
			billing: getBillingInformation(billingData),
			customer_id: paymentDetails.customerId,
			intent_id: paymentDetails.paymentIntent,
			payment_method_id: paymentDetails.paymentMethodId,
			payment_method_options: {
				card: {
					auto_capture: settings.capture_immediately
				}
			},
		}
		if (paymentDetails.createConsent) {
			confirmData.currency = paymentDetails.currency;
			confirmData.payment_consent = {
				merchant_trigger_reason: 'scheduled',
				next_triggered_by: 'merchant'
			};
		}
		request = cvcElementRef!.current!.confirm(confirmData);
	} else if (paymentDetails.createConsent) {
		// The SDK signature `PaymentMethod | PaymentMethodWithConsent`
		// doesn't model the runtime's `payment_consent` / `currency` /
		// top-level `billing` fields - cast through `unknown` so the
		// literal goes through without an `any` escape.
		request = confirmAirwallexPaymentIntent({
			intent_id: paymentDetails.paymentIntent!,
			customer_id: paymentDetails.customerId,
			client_secret: paymentDetails.clientSecret!,
			currency: paymentDetails.currency,
			element: card!,
			payment_consent: {
				merchant_trigger_reason: 'scheduled',
				next_triggered_by: 'merchant',
			},
			billing: getBillingInformation(billingData),
		} as unknown as ConfirmPaymentIntentArg);
	} else if (airwallexSaveChecked) {
		// `PaymentConsentRequest.customer_id` is required `string`, but
		// `paymentDetails.customerId` is `string | undefined` here -
		// the runtime accepts undefined; same `unknown` bridge.
		request = createAirwallexPaymentConsent({
			intent_id: paymentDetails.paymentIntent,
			customer_id: paymentDetails.customerId,
			client_secret: paymentDetails.clientSecret!,
			currency: paymentDetails.currency,
			element: card!,
			next_triggered_by: 'customer',
			billing: getBillingInformation(billingData),
		} as unknown as CreatePaymentConsentArg);
	} else {
		// Matches `PaymentMethod` exactly except the nested
		// `billing.email` (optional in our `BillingData`, required by
		// the SDK's `Billing`). The runtime accepts undefined; cast
		// keeps the literal verbatim.
		request = confirmAirwallexPaymentIntent({
			element: card!,
			id: paymentDetails.paymentIntent,
			client_secret: paymentDetails.clientSecret!,
			payment_method: {
				card: {
					name: getCardHolderName(billingData),
				},
				billing: getBillingInformation(billingData),
			},
		} as unknown as ConfirmPaymentIntentArg);
	}
	return request.then(() => {
		return paymentResponse;
	}).catch((error: unknown) => {
		const err = error as { code?: string; message?: string };
		paymentResponse.type           = errorType;
		paymentResponse.code           = err.code;
		paymentResponse.message        = err.message ?? JSON.stringify(error);
		paymentResponse.messageContext = errorContext;
		paymentResponse.orderId = paymentDetails.orderId;
		return paymentResponse;
	});
}

interface InlineCardProps {
	settings: CardSettings;
	props: Partial<PaymentMethodComponentProps>;
	// Match `PaymentMethodComponentProps`'s index signature so test
	// fixtures (typed `Record<string, unknown>` and double-cast through
	// `unknown as React.ComponentProps<typeof InlineCard>`) stay
	// assignable to the `.props` slot without test-side edits.
	[extra: string]: unknown;
}

export const InlineCard                             = ({
	settings: settings,
	props: props,
}: InlineCardProps) => {
	const [elementShow, setElementShow]             = useState(false);
	// `errorMessage` is set on SDK errors but never displayed; preserve
	// the state hook for parity with the original .jsx render shape.
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	const [errorMessage, setErrorMessage]           = useState<string | false>(false);
	const [isSubmitting, setIsSubmitting]           = useState(false);
	const [inputErrorMessage, setInputErrorMessage] = useState<string | false>(false);

	const emitResponse = props.emitResponse!;
	const billing = props.billing!;
	const ValidationInputError = props.components!.ValidationInputError!;
	const {
		onCheckoutSuccess,
		onPaymentSetup,
		onCheckoutFail,
		onCheckoutValidation,
	} = props.eventRegistration!;

	useEffect(() => {
		const options: InitOptions = {
			env: settings.environment as InitOptions['env'],
			origin: window.location.origin,
			locale: settings.locale as InitOptions['locale'],
		};
		loadAirwallex(options).then(() => {
			Airwallex!.init(options);
			const card = createAirwallexElement('card', {
				autoCapture: settings.capture_immediately,
				allowedCardNetworks: ['discover', 'visa', 'mastercard', 'maestro', 'unionpay', 'amex', 'jcb', 'diners'],
				// SDK's `CSSProperties` index signature rejects the
				// nested `'::placeholder'` object; cast through
				// `unknown` so the literal stays verbatim. Matches the
				// pattern in `assets/js/airwallex-card.ts`.
				style: {
					base: {
						fontSize: '14px',
						"::placeholder": {
							'color': 'rgba(135, 142, 153, 1)'
						},
					}
				} as unknown as InputStyle,
			});
			card?.mount('airwallex-card');
		});

		const onReady = () => {
			setElementShow(true);
			console.log('The Card element is ready.');
		};

		const onError = (event: Event) => {
			const { error } = (event as CustomEvent<{ error: { message?: string } }>).detail;
			setErrorMessage(error.message ?? false);
			console.error('There was an error', error);
		};

		const onFocus = () => {
			setInputErrorMessage('');
		};

		const onBlur = (event: Event) => {
			const { error } = (event as CustomEvent<{ error?: { message?: string } }>).detail;
			setInputErrorMessage(error?.message ?? JSON.stringify(error));
		};

		const domElement = document.getElementById('airwallex-card')!;
		domElement.addEventListener('onReady', onReady);
		domElement.addEventListener('onError', onError);
		domElement.addEventListener('onBlur', onBlur);
		domElement.addEventListener('onFocus', onFocus);
		return () => {
			domElement.removeEventListener('onReady', onReady);
			domElement.removeEventListener('onError', onError);
			domElement.removeEventListener('onFocus', onFocus);
			domElement.removeEventListener('onBlur', onBlur);
		};
	}, []);

	useEffect(() => {
		const onValidation = () => {
			if (inputErrorMessage) {
				return {
					errorMessage: __('An error has occurred. Please check your payment details.', 'airwallex-online-payments-gateway') + ` (${inputErrorMessage})`
				};
			}
			return true;
		};

		const unsubscribeAfterProcessing = onCheckoutValidation(onValidation);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		inputErrorMessage,
		onCheckoutValidation,
	]);

	useEffect(() => {
		const onSubmit = async () => {
			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						'is-airwallex-card-block': true,
					}
				}
			};
		}

		const unsubscribeAfterProcessing = onPaymentSetup(onSubmit);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		settings,
		onPaymentSetup,
		emitResponse.responseTypes.SUCCESS,
	]);

	useEffect(() => {
		const onError = ({ processingResponse }: ProcessingResponseArg) => {
			if (processingResponse?.paymentDetails?.errorMessage) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: processingResponse.paymentDetails.errorMessage,
					messageContext: emitResponse.noticeContexts.PAYMENTS,
				};
			}
			return true;
		};

		const unsubscribeAfterProcessing = onCheckoutFail(onError as (...args: unknown[]) => unknown);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		onCheckoutFail,
		emitResponse.noticeContexts.PAYMENTS,
		emitResponse.responseTypes.ERROR,
	]);

	useEffect(() => {
		const onSuccess = async ({ processingResponse }: ProcessingResponseArg) => {
			setIsSubmitting(true);
			const paymentDetails: PaymentDetails = processingResponse?.paymentDetails ?? {};

			const response = await confirmPayment({
				settings,
				paymentDetails,
				billingData: billing.billingData,
				successType: emitResponse.responseTypes.SUCCESS,
				errorType: emitResponse.responseTypes.ERROR,
				errorContext: emitResponse.noticeContexts.PAYMENTS,
			});
			if (response.type === emitResponse.responseTypes.SUCCESS || (response.type === emitResponse.responseTypes.ERROR && response.code === 'invalid_status_for_operation')) {
				location.href = response.confirmUrl as string;
			} else {
				try {
					const updateOrderStatusResponse = await updateOrderStatusAfterPaymentDecline(response.orderId!);
					const errMessage = updateOrderStatusResponse?.success === false ? updateOrderStatusResponse?.message : response.message;
					response.message = errMessage
				} catch (e) {
					const ex = e as AjaxErrorLike;
					let errMessage = ex.responseText;
					if (ex.responseJSON && ex.responseJSON.message) {
						errMessage = ex.responseJSON.message;
					}
					response.message = errMessage
				}
				setIsSubmitting(false);
				return response;
			}
		};

		const unsubscribeAfterProcessing = onCheckoutSuccess(onSuccess as (...args: unknown[]) => unknown);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		onCheckoutSuccess,
		emitResponse.noticeContexts.PAYMENTS,
		emitResponse.responseTypes.SUCCESS,
		emitResponse.responseTypes.ERROR,
	]);

	return (
		<>
			<div className                     ='airwallex-checkout-loading-mask' style={{ display: isSubmitting ? 'block' : 'none' }}></div>
			<div id                            ="airwallex-card" style={{
				display: elementShow ? 'flex' : 'none',
				border: "1px solid var(--Border-decorative, rgba(232, 234, 237, 1))",
				background: "rgb(250, 250, 251)",
				padding: "0 16px",
				marginBottom: "6px",
				marginTop: "4px",
				minHeight: "40px",
				borderRadius: "4px",
				alignItems: "center",
				width: "400px",
			}}></div>
			<ValidationInputError errorMessage ={inputErrorMessage} />
		</>
	);
};

interface AirwallexSaveCardProps {
	settings: CardSettings;
	emitResponse?: PaymentMethodComponentProps['emitResponse'];
	billing?: PaymentMethodComponentProps['billing'];
	token?: string;
	eventRegistration?: PaymentMethodComponentProps['eventRegistration'];
}

export const AirwallexSaveCard = (props: AirwallexSaveCardProps) => {
	const [isCVCCompleted, setIsCVCCompleted] = useState(false);
	const [isHideCvcElement, setIsHideCvcElement] = useState(false);
	const [cvcLength, setCvcLength] = useState(3);
	const {
		settings,
		token,
	} = props;
	const emitResponse = props.emitResponse!;
	const billing = props.billing!;
	const { onCheckoutSuccess } = props.eventRegistration!;
	const cvcElementRef = useRef<CvcElementLike | null>(null);
	const onChange = (event: Event) => {
		const { complete } = (event as CustomEvent<{ complete: boolean }>).detail;
		setIsCVCCompleted(complete);
	};

	useEffect(() => {
		const { tokens } = settings;
		const tokenData = tokens?.[token as string];
		setIsHideCvcElement(!!tokenData?.is_hide_cvc_element);
		setCvcLength(['amex', 'american express'].includes((tokenData?.type ?? '').toLowerCase()) ? 4 : 3);
	}, [token, settings]);

	useEffect(() => {
		let cvcElement: CvcElementLike | null = null;
		const options: InitOptions = {
			env: settings.environment as InitOptions['env'],
			locale: settings.locale as InitOptions['locale'],
			origin: window.location.origin,
		};
		loadAirwallex(options).then(() => {
			Airwallex!.init(options);
			// `Airwallex.createElement('cvc', ...)` returns the global
			// CDN-typed `Payment.CvcElementType`; cast through `unknown`
			// to our local `CvcElementLike` shape (see top of file).
			cvcElement = Airwallex!.createElement('cvc', {
				style: {
					base: {
						fontSize: '14px',
						"::placeholder": {
							color: 'rgba(135, 142, 153, 1)',
						},
					},
				} as unknown as InputStyle,
				placeholder: __('CVC', 'airwallex-online-payments-gateway'),
				cvcLength,
			}) as unknown as CvcElementLike | null;
			cvcElementRef.current = cvcElement;
			setIsCVCCompleted(false);
			cvcElement?.mount('airwallex-cvc');
			cvcElement?.on('change', onChange);
		});

		return () => {
			if (cvcElement) {
				cvcElement.destroy();
			}
		};
	}, [cvcLength]);

	useEffect(() => {
		const onSuccess = async ({ processingResponse }: ProcessingResponseArg) => {
			const { tokens } = settings;
			const tokenData = tokens?.[token as string];
			if (!awxEmbeddedCardData.isSkipCVCEnabled && !isCVCCompleted && !tokenData?.is_hide_cvc_element) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: awxEmbeddedCardData.CVCIsNotCompletedMessage,
					messageContext: emitResponse.noticeContexts.PAYMENTS,
				};
			}
			const paymentDetails: PaymentDetails = processingResponse?.paymentDetails ?? {};
			const response = await confirmPayment({
				settings,
				paymentDetails,
				cvcElementRef,
				billingData: billing.billingData,
				successType: emitResponse.responseTypes.SUCCESS,
				errorType: emitResponse.responseTypes.ERROR,
				errorContext: emitResponse.noticeContexts.PAYMENTS,
			});
			if (response.type === emitResponse.responseTypes.SUCCESS || (response.type === emitResponse.responseTypes.ERROR && response.code === 'invalid_status_for_operation')) {
				location.href = (response.confirmUrl as string) + "&token_id=" + parseInt(token as string);
			} else {
				try {
					const updateOrderStatusResponse = await updateOrderStatusAfterPaymentDecline(response.orderId!);
					const errMessage = updateOrderStatusResponse?.success === false ? updateOrderStatusResponse?.message : response.message;
					response.message = errMessage
				} catch (e) {
					const ex = e as AjaxErrorLike;
					let errMessage = ex.responseText;
					if (ex.responseJSON && ex.responseJSON.message) {
						errMessage = ex.responseJSON.message;
					}
					response.message = errMessage
				}
				return response;
			}
		};

		const unsubscribeAfterProcessing = onCheckoutSuccess(onSuccess as (...args: unknown[]) => unknown);
		return () => unsubscribeAfterProcessing();
	}, [
		onCheckoutSuccess,
		emitResponse?.responseTypes.SUCCESS,
		emitResponse?.responseTypes.ERROR,
		isCVCCompleted,
		token,
	]);

	return (
		<div style={{ display: isHideCvcElement ? 'none' : 'block' }}>
			<div className="cvc-title" style={{ marginBottom: "4px" }}>Security code</div>
			<div id="airwallex-cvc" className="cvc-container" style={{
				border: "1px solid var(--Border-decorative, rgba(232, 234, 237, 1))",
				background: "rgb(250, 250, 251)",
				padding: "0 16px",
				marginBottom: "18px",
				minHeight: "40px",
				borderRadius: "4px",
				display: "flex",
				alignItems: "center",
				width: "288px",
			}}></div>
		</div>
	);
};
