export function handleCurrencySwitchingForKlarna({
	awxEmbeddedLPMData,
	originalCurrency,
	setRequiredCurrency,
	displayCurrencySwitchingInfo,
	displayCurrencyIneligibleInfo,
}) {
	const paymentMethodName = awxEmbeddedLPMData?.paymentMethodNames?.['Klarna'];
	const $ = jQuery;
	const selectedCountry = $('#billing_country').val();
	const { supportedCountryCurrency } = awxEmbeddedLPMData.airwallex_klarna || {};
	const { availableCurrencies } = awxEmbeddedLPMData;

	let requiredCurrency = supportedCountryCurrency?.[selectedCountry];

	if (!requiredCurrency) {
		$('.wc-airwallex-lpm-country-ineligible').show();
		return false;
	}

	setRequiredCurrency(requiredCurrency);
	if (originalCurrency === requiredCurrency) {
		return true;
	}

	if (availableCurrencies?.includes(requiredCurrency)) {
		displayCurrencySwitchingInfo(paymentMethodName, originalCurrency, requiredCurrency);
		return true;
	}

	displayCurrencyIneligibleInfo(paymentMethodName, originalCurrency);
	return false;
}
