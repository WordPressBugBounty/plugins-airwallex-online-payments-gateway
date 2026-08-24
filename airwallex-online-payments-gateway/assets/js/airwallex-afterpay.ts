// Structural subset of `EmbeddedLPMData`: only the fields this helper
// reads. Lets test fixtures pass minimal shapes without inflating them
// with `ajaxUrl`/`nonce`/`originalCurrency`/`textTemplate` (production
// callers pass the full `awxEmbeddedLPMData` global, which satisfies
// this superset trivially).
type AfterpayLPMData = Pick<EmbeddedLPMData, 'paymentMethodNames' | 'airwallex_afterpay' | 'availableCurrencies' | 'owningEntity'>;

interface CurrencySwitcherArgs {
	awxEmbeddedLPMData: AfterpayLPMData;
	originalCurrency: string;
	setRequiredCurrency: (val: string) => void;
	displayCurrencySwitchingInfo: (paymentMethodName: string | undefined, originalCurrency: string, requiredCurrency: string) => void;
	displayCurrencyIneligibleInfo: (paymentMethodName: string | undefined, originalCurrency: string) => void;
}

export function handleCurrencySwitchingForAfterpay({
	awxEmbeddedLPMData,
	originalCurrency,
	setRequiredCurrency,
	displayCurrencySwitchingInfo,
	displayCurrencyIneligibleInfo,
}: CurrencySwitcherArgs): boolean {
	const paymentMethodName = awxEmbeddedLPMData?.paymentMethodNames?.['Afterpay'];
	const $ = jQuery;
	const billingCountry = $('#billing_country').val() as string;
	const { supportedCountryCurrency, supportedEntityCurrencies } = awxEmbeddedLPMData?.airwallex_afterpay || {};
	const { availableCurrencies, owningEntity } = awxEmbeddedLPMData;

	$(".wc-airwallex-afterpay-supported-countries-form").hide();
	const entitySupportedCurrencies = supportedEntityCurrencies?.[owningEntity as string];
	if (!entitySupportedCurrencies) {
		$('.wc-airwallex-lpm-entity-ineligible').show();
		return false;
	}

	if (!availableCurrencies || !availableCurrencies.length) {
		if (entitySupportedCurrencies.includes(originalCurrency)) {
			return true;
		}
	}

	if (!availableCurrencies || !availableCurrencies.length || !availableCurrencies.includes(originalCurrency)) {
		displayCurrencyIneligibleInfo(paymentMethodName, originalCurrency);
		return false;
	}

	const billingCurrency = supportedCountryCurrency?.[billingCountry];
	if (availableCurrencies && availableCurrencies.includes(originalCurrency) && (owningEntity !== 'AIRWALLEX_HK' || billingCurrency === originalCurrency)) {
		return true;
	}

	let requiredCurrency: string | undefined;
	if (billingCurrency) {
		requiredCurrency = billingCurrency;
		if (!entitySupportedCurrencies.includes(requiredCurrency)) {
			requiredCurrency = '';
		}
	}

	if (!requiredCurrency && entitySupportedCurrencies.length === 1) {
		requiredCurrency = entitySupportedCurrencies[0];
	}

	if (requiredCurrency) {
		setRequiredCurrency(requiredCurrency);
		displayCurrencySwitchingInfo(paymentMethodName, originalCurrency, requiredCurrency);
		return true;
	}

	const afterpayCountryKey = 'airwallex_afterpay_country';
	const afterpayCountry = localStorage.getItem(afterpayCountryKey);
	$(".wc-airwallex-afterpay-supported-countries-form").show();

	const $li = $(".awx-afterpay-countries li");
	const $input = $(".awx-afterpay-countries input");
	$li.each(function (this: HTMLElement) {
		if ($(this).data("value") === afterpayCountry) {
			$input.val($(this).html());
		}
	});
	const showCountries = function () {
		$(".awx-afterpay-countries .countries").fadeIn(300);
		const afterpayCountry = localStorage.getItem(afterpayCountryKey);
		if (afterpayCountry) {
			$(".awx-afterpay-countries li").each(function (this: HTMLElement) {
				$(this).removeClass("selected");
				if ($(this).data("value") === afterpayCountry) {
					$(this).addClass("selected");
				}
			});
		}
	};
	$input.off('focus').on('focus', showCountries);
	$('.awx-afterpay-countries .input-icon').off('click').on('click', showCountries);
	$input.off('blur').on('blur', function () {
		$(".awx-afterpay-countries .countries").fadeOut(300);
	});

	const renderAfterpay = function (paymentMethodName: string | undefined, originalCurrency: string, requiredCurrency: string) {
		setRequiredCurrency(requiredCurrency);
		if (originalCurrency !== requiredCurrency) {
			displayCurrencySwitchingInfo(paymentMethodName, originalCurrency, requiredCurrency);
		} else {
			$('.wc-airwallex-currency-switching').hide();
			$('.woocommerce-checkout .wc-airwallex-alert-box').hide();
		}
	};
	$li.off('click').on('click', function (this: HTMLElement) {
		localStorage.setItem(afterpayCountryKey, $(this).data("value"));
		$('.wc-airwallex-afterpay-supported-countries-form input').val($(this).html());
		requiredCurrency = supportedCountryCurrency?.[$(this).data("value")];
		renderAfterpay(paymentMethodName, originalCurrency, requiredCurrency as string);
	});
	requiredCurrency = supportedCountryCurrency?.[afterpayCountry as string];
	if (requiredCurrency) {
		renderAfterpay(paymentMethodName, originalCurrency, requiredCurrency);
		return true;
	}
	return false;
}
