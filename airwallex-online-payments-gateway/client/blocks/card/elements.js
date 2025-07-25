import { useEffect, useRef, useState } from 'react';
import {
	loadAirwallex,
	createElement as createAirwallexElement,
	destroyElement as destroyAirwallexElement,
	confirmPaymentIntent as confirmAirwallexPaymentIntent,
	createPaymentConsent as createAirwallexPaymentConsent,
	getElement as getAirwallexElement,
	init as initAirwallex,
} from 'airwallex-payment-elements';
import { __ } from '@wordpress/i18n';
import { getCardHolderName, getBillingInformation } from '../utils';

const confirmPayment      = ({
	settings,
	paymentDetails,
	cvcElementRef,
	billingData,
	successType,
	errorType,
	errorContext,
}) => {
	const airwallexSaveChecked = document.getElementById('airwallex-save')?.checked;
	const separator = settings.confirm_url.includes('?') ? '&' : '?';
	const confirmUrl = `${settings.confirm_url}${separator}order_id=${paymentDetails.orderId}&intent_id=${paymentDetails.paymentIntent}&is_airwallex_save_checked=${airwallexSaveChecked}`;
	
	const card            = getAirwallexElement('card');
	const paymentResponse = { type: successType };
	paymentResponse.confirmUrl = confirmUrl;

	let request;
	if (paymentDetails.paymentMethodId) {
		let confirmData = {
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
		request = cvcElementRef.current.confirm(confirmData);
	} else if (paymentDetails.createConsent) {
		request = createAirwallexPaymentConsent({
			intent_id: paymentDetails.paymentIntent,
			customer_id: paymentDetails.customerId,
			client_secret: paymentDetails.clientSecret,
			currency: paymentDetails.currency,
			element: card,
			next_triggered_by: 'merchant',
			billing: getBillingInformation(billingData),
		});
	} else if (airwallexSaveChecked) {
		request = createAirwallexPaymentConsent({
			intent_id: paymentDetails.paymentIntent,
			customer_id: paymentDetails.customerId,
			client_secret: paymentDetails.clientSecret,
			currency: paymentDetails.currency,
			element: card,
			next_triggered_by: 'customer',
			billing: getBillingInformation(billingData),
		});
	} else {
		request = confirmAirwallexPaymentIntent({
			element: card,
			id: paymentDetails.paymentIntent,
			client_secret: paymentDetails.clientSecret,
			payment_method: {
				card: {
					name: getCardHolderName(billingData),
				},
				billing: getBillingInformation(billingData),
			},
		});
	}
	return request.then((response) => {
		return paymentResponse;
	}).catch((error) => {
		paymentResponse.type           = errorType;
		paymentResponse.code           = error.code;
		paymentResponse.message        = error.message ?? JSON.stringify(error);
		paymentResponse.messageContext = errorContext;
		return paymentResponse;
	});
}

export const InlineCard                             = ({
	settings: settings,
	props: props,
}) => {
	const [elementShow, setElementShow]             = useState(false);
	const [errorMessage, setErrorMessage]           = useState(false);
	const [isSubmitting, setIsSubmitting]           = useState(false);
	const [inputErrorMessage, setInputErrorMessage] = useState(false);

	const {
		emitResponse,
		billing,
	} = props;
	const {
		ValidationInputError,
		LoadingMask,
	} = props.components;
	const {
		onCheckoutSuccess,
		onPaymentSetup,
		onCheckoutFail,
		onCheckoutValidation,
	} = props.eventRegistration;

	useEffect(() => {
		loadAirwallex({
			env: settings.environment,
			origin: window.location.origin,
			locale: settings.locale,
		}).then(() => {
			const card = createAirwallexElement('card', {
				autoCapture: settings.capture_immediately,
				allowedCardNetworks: ['discover', 'visa', 'mastercard', 'maestro', 'unionpay', 'amex', 'jcb', 'diners'],
				style: {
					base: {
						fontSize: '14px',
						"::placeholder": {
							'color': 'rgba(135, 142, 153, 1)'
						},
					}
				}
			});
			card.mount('airwallex-card');
		});

		const onReady = (event) => {
			setElementShow(true);
			console.log('The Card element is ready.');
		};

		const onError       = (event) => {
			const { error } = event.detail;
			setErrorMessage(error.message);
			console.error('There was an error', error);
		};

		const onFocus = (_event) => {
			setInputErrorMessage('');
		};

		const onBlur        = (event) => {
			const { error } = event.detail;
			setInputErrorMessage(error?.message ?? JSON.stringify(error));
		};

		const domElement = document.getElementById('airwallex-card');
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
		const onError = ({ processingResponse }) => {
			if (processingResponse?.paymentDetails?.errorMessage) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: processingResponse.paymentDetails.errorMessage,
					messageContext: emitResponse.noticeContexts.PAYMENTS,
				};
			}
			return true;
		};

		const unsubscribeAfterProcessing = onCheckoutFail(onError);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		onCheckoutFail,
		emitResponse.noticeContexts.PAYMENTS,
		emitResponse.responseTypes.ERROR,
	]);

	useEffect(() => {
		const onSuccess          = async ({ processingResponse }) => {
			setIsSubmitting(true);
			const paymentDetails = processingResponse.paymentDetails || {};

			const response = await confirmPayment({
				settings,
				paymentDetails,
				billingData: billing.billingData,
				successType: emitResponse.responseTypes.SUCCESS,
				errorType: emitResponse.responseTypes.ERROR,
				errorContext: emitResponse.noticeContexts.PAYMENTS,
			});
			if (response.type === emitResponse.responseTypes.SUCCESS || (response.type === emitResponse.responseTypes.ERROR && response.code === 'invalid_status_for_operation')) {
				location.href = response.confirmUrl;
			} else {
				setIsSubmitting(false);
				return response;
			}
		};

		const unsubscribeAfterProcessing = onCheckoutSuccess(onSuccess);
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

export const AirwallexSaveCard = (props) => {
	const [isCVCCompleted, setIsCVCCompleted] = useState(false);
	const [isHideCvcElement, setIsHideCvcElement] = useState(false);
	const [cvcLength, setCvcLength] = useState(3);
	const {
		emitResponse,
		settings,
		billing,
		token,
	} = props;
	const { onCheckoutSuccess } = props.eventRegistration;
	const cvcElementRef = useRef(null);
	const onChange = (event) => {
		setIsCVCCompleted(event.detail.complete);
	};

	useEffect(() => {
		const { tokens } = settings;
		const tokenData = tokens?.[token];
		setIsHideCvcElement(tokenData?.is_hide_cvc_element);
		setCvcLength(['amex', 'american express'].includes(tokenData?.type?.toLowerCase()) ? 4 : 3);
	}, [token, settings]);

	useEffect(() => {
		let cvcElement;
		loadAirwallex({
			env: settings.environment,
			locale: settings.locale,
			origin: window.location.origin,
		}).then(() => {
			cvcElement = Airwallex.createElement('cvc', {
				style: {
					base: {
						fontSize: '14px',
						"::placeholder": {
							color: 'rgba(135, 142, 153, 1)',
						},
					},
				},
				placeholder: __('CVC', 'airwallex-online-payments-gateway'),
				cvcLength,
			});
			cvcElementRef.current = cvcElement;
			setIsCVCCompleted(false);
			cvcElement.mount('airwallex-cvc');
			cvcElement.on('change', onChange);
		});
	
		return () => {
			if (cvcElement) {
				cvcElement.destroy();
			}
		};
	}, [cvcLength]);

	useEffect(() => {
		const onSuccess = async ({ processingResponse }) => {
			const { tokens } = settings;
			const tokenData = tokens?.[token];
			if (!awxEmbeddedCardData.isSkipCVCEnabled && !isCVCCompleted && !tokenData?.is_hide_cvc_element) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: awxEmbeddedCardData.CVCIsNotCompletedMessage,
					messageContext: emitResponse.noticeContexts.PAYMENTS,
				};
			}
			const paymentDetails = processingResponse.paymentDetails || {};
			const response = await confirmPayment({
				settings,
				paymentDetails,
				cvcElementRef,
				billingData: billing.billingData,
				successType: emitResponse.responseTypes.SUCCESS,
				errorType: emitResponse.responseTypes.ERROR,
				errorContext: emitResponse.noticeContexts.PAYMENTS,
			});
			if (response.type === emitResponse.responseTypes.SUCCESS || 
				(response.type === emitResponse.responseTypes.ERROR && response.code === 'invalid_status_for_operation')) {
				location.href = response.confirmUrl;
			} else {
				return response;
			}
		};

		const unsubscribeAfterProcessing = onCheckoutSuccess(onSuccess);
		return () => unsubscribeAfterProcessing();
	}, [
		onCheckoutSuccess,
		emitResponse.responseTypes.SUCCESS,
		emitResponse.responseTypes.ERROR,
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
