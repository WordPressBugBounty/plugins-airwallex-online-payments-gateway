import { useEffect, useState, useRef } from 'react';
import { __ } from '@wordpress/i18n';
import {
	getReplacedText,
	getBrowserInfo,
	getLocaleFromBrowserLanguage,
	getSessionId,
} from '../utils';
import { createQuote } from '../api';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyProps = any;

interface LpmIcon {
	url?: string;
	alt?: string;
}

interface LabelProps {
	title: string;
	icon: LpmIcon;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	components?: any;
	[extra: string]: unknown;
}

export const AirwallexLpmLabel = ({
	title,
	icon,
	...props
}: LabelProps) => {
	const { PaymentMethodLabel } = props.components;
	const { url, alt } = icon;

	return <>
		<PaymentMethodLabel text={title} />
		<img src={url} alt={alt} style={{ marginLeft: 'auto', marginRight: '5px' }} />
	</>;
}

export const AirwallexLpmContent = ({
	settings,
	description,
	CustomPaymentComponent = null,
	...props
}: AnyProps) => {
	const { eventRegistration, emitResponse, billing, activePaymentMethod } = props;
	const { LoadingMask } = props.components;
	const { onCheckoutValidation, onPaymentSetup, onCheckoutFail } = eventRegistration;
	const { currency } = billing;
	const { paymentMethodName, paymentMethodDocURL } = settings;
	const { currencyIneligibleCWOff, currencyIneligibleCWOn } = settings.textTemplate;
	const { criticalIcon, infoIcon } = settings.alterBoxIcons;
	const [showEntityIneligible, setShowEntityIneligible] = useState(false);
	const [showCountryIneligible, setShowCountryIneligible] = useState(false);
	const [showCurrencyIneligibleCWOff, setShowCurrencyIneligibleCWOff] = useState(false);
	const [showCurrencyIneligibleCWOn, setShowCurrencyIneligibleCWOn] = useState(false);
	const [isLoadingCurrencySwitching, setIsLoadingCurrencySwitching] = useState(false);
	const [convertCurrency, setConvertCurrency] = useState('');
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	const [currentQuote, setCurrentQuote] = useState<any>({});
	const [showCustomPaymentComponent, setShowCustomPaymentComponent] = useState(false);
	const isPaymentAborted = useRef(false);

	const updateCurrencySwitchingInfo = async (requiredCurrency: string) => {
		let showElement = false, conversionRate = '', convertedAmount = '';
		disableConfirmButton(true);
		setIsLoadingCurrencySwitching(true);
		await createQuote(currency.code, requiredCurrency, settings).then((response) => {
			const { quote } = response;
			if (quote) {
				setCurrentQuote(quote);
				showElement = true;
				const data = {
					'$$payment_method_name$$': paymentMethodName,
					'$$original_currency$$': quote.paymentCurrency,
					'$$converted_currency$$': quote.targetCurrency,
					'$$conversion_rate$$': quote.clientRate,
					'$$converted_amount$$': quote.targetAmount,
				};
				conversionRate = getReplacedText(settings?.textTemplate?.conversionRate, data);
				convertedAmount = getReplacedText(settings?.textTemplate?.convertedAmount, data);
				updateQuoteExpire({
					paymentMethodName,
					conversionRate,
					convertedAmount,
					requiredCurrency,
					baseAmount: quote.paymentAmount,
				});
				setShowCurrencyIneligibleCWOn(true);
				setShowCurrencyIneligibleCWOff(false);
				disableConfirmButton(false);
			} else {
				showElement = false;
				conversionRate = '';
				convertedAmount = '';
				setShowCurrencyIneligibleCWOn(false);
				setShowCurrencyIneligibleCWOff(true);
				disableConfirmButton(true);
			}
		}).catch((error) => {
			setShowCurrencyIneligibleCWOn(false);
			setShowCurrencyIneligibleCWOff(true);
			console.error(error);
		});
		setIsLoadingCurrencySwitching(false);
		disableConfirmButton(false);
		(document.getElementById('wc-block-airwallex-currency-switching-convert-rate') as HTMLElement).innerHTML = conversionRate;
		(document.getElementById('wc-block-airwallex-currency-switching-converted-amount') as HTMLElement).innerHTML = convertedAmount;
		(document.getElementById('wc-block-airwallex-currency-switching-container') as HTMLElement).style.display = showElement ? 'flex' : 'none';
	};

	const updateQuoteExpire = ({
		paymentMethodName,
		conversionRate,
		convertedAmount,
		requiredCurrency,
		baseAmount
	}: {
		paymentMethodName: string;
		conversionRate: string;
		convertedAmount: string;
		requiredCurrency: string;
		baseAmount: string;
	}) => {
		try {
			(document.getElementById('wc-airwallex-quote-expire-convert-text') as HTMLElement).innerHTML =
				getReplacedText(currencyIneligibleCWOn, {'$$payment_method_name$$': paymentMethodName, '$$original_currency$$': currency.code, '$$converted_currency$$': requiredCurrency});
			(document.getElementById('wc-airwallex-currency-switching-base-amount') as HTMLElement).innerHTML = baseAmount;
			(document.getElementById('wc-airwallex-currency-switching-conversion-rate') as HTMLElement).innerHTML = conversionRate;
			(document.getElementById('wc-airwallex-currency-switching-converted-amount') as HTMLElement).innerHTML = convertedAmount;
		} catch (error) {
			console.warn(error);
		}
	};

	const showQuoteExpire = () => {
		try {
			(document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire')[0] as HTMLElement).style.display = 'block';
			(document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-mask')[0] as HTMLElement).style.display = 'block';
		} catch (error) {
			console.warn(error);
		}
	};

	const hideQuoteExpire = () => {
		try {
			(document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire')[0] as HTMLElement).style.display = 'none';
			(document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-mask')[0] as HTMLElement).style.display = 'none';
		} catch (error) {
			console.warn(error);
		}
	};

	const disableConfirmButton = function (disable: boolean) {
		try {
			if (disable) {
				(document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-place-order-mask')[0] as HTMLElement).style.display = 'block';
			} else {
				(document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-place-order-mask')[0] as HTMLElement).style.display = 'none';
			}
		} catch (error) {
			console.warn(error);
		}
	}

	const disablePlaceOrderButton = function (disable: boolean) {
		try {
			if (disable) {
				document.getElementsByClassName('wc-block-components-checkout-place-order-button')[0].setAttribute('disabled', 'disabled');
			} else {
				document.getElementsByClassName('wc-block-components-checkout-place-order-button')[0].removeAttribute('disabled');
			}
		} catch (error) {
			console.warn(error);
		}
	}

	useEffect( () => {
		const currencySwitchingContainerElement = document.getElementById('wc-block-airwallex-currency-switching-container');
		if (currencySwitchingContainerElement) {
			currencySwitchingContainerElement.style.display = 'none';
		}
		if (typeof props.handleCurrencySwitching === 'function') {
			props.handleCurrencySwitching({
				country: billing.billingAddress.country,
				currency,
				settings,
				paymentMethod: activePaymentMethod,
				updateCurrencySwitchingInfo,
				setShowEntityIneligible,
				setShowCountryIneligible,
				setShowCurrencyIneligibleCWOff,
				setShowCurrencyIneligibleCWOn,
				setConvertCurrency,
				setShowCustomPaymentComponent,
			});
		}
		try {
			(document.getElementById('wc-airwallex-quote-expire-confirm') as HTMLElement).innerHTML = (document.getElementsByClassName('wc-block-components-checkout-place-order-button')[0] as HTMLElement)?.innerText;
		} catch (error) {
			console.warn(error);
		}
	}, [
		billing.billingAddress.country,
		billing.cartTotal.value,
	] );

	useEffect(() => {
		const onValidation = () => {
			isPaymentAborted.current = false;
			if (showEntityIneligible) {
				return {
					context: emitResponse.noticeContexts.PAYMENTS,
					errorMessage: __('Invalid merchant entity.', 'airwallex-online-payments-gateway'),
				};
			}
			if (showCountryIneligible || showCurrencyIneligibleCWOff) {
				return {
					context: emitResponse.noticeContexts.PAYMENTS,
					errorMessage: __('Please use a different payment method.', 'airwallex-online-payments-gateway'),
				};
			}
			if (Object.keys(currentQuote).length === 0) {
				return true;
			}
			if (currentQuote && currentQuote.refreshAt && new Date(currentQuote.refreshAt).getTime() >= new Date().getTime()) {
				return true;
			}
			updateCurrencySwitchingInfo(convertCurrency);
			showQuoteExpire();

			return new Promise((resolve) => {
				const close = document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-close')[0] as HTMLElement | undefined;
				const back = document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-place-back')[0] as HTMLElement | undefined;
				const order = document.getElementsByClassName('wc-airwallex-currency-switching-quote-expire-place-order')[0] as HTMLElement | undefined;

				if (!close || !back || !order) {
					console.warn('Quote expire pop up modal not found.');
					resolve(false);
					return;
				}

				close.onclick = () => {
					hideQuoteExpire();
					isPaymentAborted.current = true;
					resolve({
						context: emitResponse.noticeContexts.PAYMENTS,
						errorMessage: __('Payment aborted.', 'airwallex-online-payments-gateway'),
					});
				};
				back.onclick = () => {
					hideQuoteExpire();
					isPaymentAborted.current = true;
					resolve({
						context: emitResponse.noticeContexts.PAYMENTS,
						errorMessage: __('Payment aborted.', 'airwallex-online-payments-gateway'),
					});
				};
				order.onclick = () => {
					hideQuoteExpire();
					resolve(true);
				};
			});
		};

		const unsubscribeAfterProcessing = onCheckoutValidation(onValidation);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		showEntityIneligible,
		showCountryIneligible,
		showCurrencyIneligibleCWOff,
		currentQuote,
		convertCurrency,
		onCheckoutValidation,
	]);

	useEffect(() => {
		const onSubmit = async () => {
			const deviceData = getBrowserInfo(getSessionId());
			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						is_payment_aborted: isPaymentAborted.current ? 'true' : 'false',
						airwallex_device_data: JSON.stringify(deviceData),
						airwallex_target_currency: convertCurrency,
						airwallex_browser_language: getLocaleFromBrowserLanguage(),
					},
				},
			};
		}

		const unsubscribeAfterProcessing = onPaymentSetup(onSubmit);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		settings,
		convertCurrency,
		onPaymentSetup,
		emitResponse.responseTypes.SUCCESS,
	]);

	useEffect(() => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const onError = ({ processingResponse }: any) => {
			if (processingResponse?.paymentDetails?.message) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: processingResponse.paymentDetails.message,
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

	return (
		<div>
			<LoadingMask
				isLoading={isLoadingCurrencySwitching}
				screenReaderLabel={ __(
					'Loading currency switching detail…',
					'airwallex-online-payments-gateway'
				) }
			>
				{description}
				<span style={{ display: isLoadingCurrencySwitching ? 'inline-block' : 'none' }} className="wc-airwallex-loader"></span>
				<EntityIneligibleAlert
					shouldDisplay={showEntityIneligible}
					icon={criticalIcon}
				/>
				<CountryIneligibleAlert
					shouldDisplay={showCountryIneligible}
					methodName={paymentMethodName}
					docUrl={paymentMethodDocURL}
					icon={criticalIcon}
					test={isLoadingCurrencySwitching}
				/>
				{CustomPaymentComponent && showCustomPaymentComponent && (
					<CustomPaymentComponent
						paymentMethod={activePaymentMethod}
						currency={currency}
						setConvertCurrency={setConvertCurrency}
						setShowCurrencyIneligibleCWOn={setShowCurrencyIneligibleCWOn}
						updateCurrencySwitchingInfo={updateCurrencySwitchingInfo}
						disablePlaceOrderButton={disablePlaceOrderButton}
					/>
				)}
				<CurrencyIneligibleCWOffAlert
					shouldDisplay={showCurrencyIneligibleCWOff}
					text={getReplacedText(currencyIneligibleCWOff, {'$$payment_method_name$$': paymentMethodName, '$$original_currency$$': currency.code})}
					icon={criticalIcon}
				/>
				<CurrencyIneligibleCWOnAlert
					shouldDisplay={showCurrencyIneligibleCWOn}
					text={getReplacedText(currencyIneligibleCWOn, {'$$payment_method_name$$': paymentMethodName, '$$original_currency$$': currency.code, '$$converted_currency$$': convertCurrency})}
					icon={infoIcon}
				/>
			</LoadingMask>
		</div>
	);
};

export const AirwallexLpmContentAdmin = ({
	description,
}: { description: string; [extra: string]: unknown }) => {
	return <div>{description}</div>;
};

const EntityIneligibleAlert = ({
	icon,
	shouldDisplay,
}: { icon: string; shouldDisplay: boolean }) => {
	return (
		<div style={{ display: shouldDisplay ? 'flex' : 'none' }} className='wc-airwallex-alert-box wc-airwallex-error'>
			<img src={icon}></img>
			<div>
				{__('Invalid merchant entity.', 'airwallex-online-payments-gateway')}
			</div>
		</div>
	);
};

const CountryIneligibleAlert = ({
	methodName,
	docUrl,
	icon,
	shouldDisplay,
}: { methodName: string; docUrl: string; icon: string; shouldDisplay: boolean; test?: boolean }) => {
	return (
		<div style={{ display: shouldDisplay ? 'flex' : 'none' }} className='wc-airwallex-alert-box wc-airwallex-error'>
			<img src={icon}></img>
			<div>
				{methodName}{__(' is not available in your country. Please change your billing address to a ', 'airwallex-online-payments-gateway')}
				<a href={docUrl} target='_blank'>{__('compatible country', 'airwallex-online-payments-gateway')}</a>
				{__(' or choose a different payment method.', 'airwallex-online-payments-gateway')}
			</div>
		</div>
	);
};

const CurrencyIneligibleCWOffAlert = ({
	text,
	icon,
	shouldDisplay
}: { text: string; icon: string; shouldDisplay: boolean }) => {
	return (
		<div style={{ display: shouldDisplay ? 'flex' : 'none' }} className='wc-airwallex-alert-box wc-airwallex-error'>
			<img src={icon}></img>
			<div>{text}</div>
		</div>
	);
};

const CurrencyIneligibleCWOnAlert = ({
	text,
	icon,
	shouldDisplay
}: { text: string; icon: string; shouldDisplay: boolean }) => {
	return (
		<div style={{ display: shouldDisplay ? 'flex' : 'none' }} className='wc-airwallex-alert-box wc-airwallex-info'>
			<img src={icon}></img>
			<div>{text}</div>
		</div>
	);
};
