// Structural subset of `EmbeddedLPMData`: only the fields this helper
// reads. Lets test fixtures pass minimal shapes without inflating them
// with `ajaxUrl`/`nonce`/`originalCurrency`/`textTemplate` (production
// callers pass the full `awxEmbeddedLPMData` global, which satisfies
// this superset trivially).
type KlarnaLPMData = Pick<EmbeddedLPMData, 'paymentMethodNames' | 'airwallex_klarna' | 'availableCurrencies'>;

interface CurrencySwitcherArgs {
	awxEmbeddedLPMData: KlarnaLPMData;
	originalCurrency: string;
	setRequiredCurrency: (val: string) => void;
	displayCurrencySwitchingInfo: (paymentMethodName: string | undefined, originalCurrency: string, requiredCurrency: string) => void;
	displayCurrencyIneligibleInfo: (paymentMethodName: string | undefined, originalCurrency: string) => void;
}

export function handleCurrencySwitchingForKlarna({
	awxEmbeddedLPMData,
	originalCurrency,
	setRequiredCurrency,
	displayCurrencySwitchingInfo,
	displayCurrencyIneligibleInfo,
}: CurrencySwitcherArgs): boolean {
	const paymentMethodName = awxEmbeddedLPMData?.paymentMethodNames?.['Klarna'];
	const $ = jQuery;
	const selectedCountry = $('#billing_country').val() as string;
	const { supportedCountryCurrency } = awxEmbeddedLPMData.airwallex_klarna || {};
	const { availableCurrencies } = awxEmbeddedLPMData;

	const requiredCurrency = supportedCountryCurrency?.[selectedCountry];

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
