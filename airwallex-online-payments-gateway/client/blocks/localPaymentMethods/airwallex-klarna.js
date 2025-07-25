import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import {
    AirwallexLpmLabel,
    AirwallexLpmContent,
    AirwallexLpmContentAdmin,
} from './elements';

const settings = getSetting('airwallex_klarna_data', {});
const icon = settings.icon ?? {};

const title       = settings?.title ?? __('Klarna', 'airwallex-online-payments-gateway');
const description = settings?.description ?? '';

const canMakePayment = () => {
	return settings?.enabled ?? false;
}

const handleCurrencySwitching = ({
	country,
	currency,
	settings,
	paymentMethod,
	updateCurrencySwitchingInfo,
	setShowEntityIneligible,
	setShowCountryIneligible,
	setShowCurrencyIneligibleCWOff,
	setShowCurrencyIneligibleCWOn,
	setConvertCurrency,
}) => {
    if (!settings[paymentMethod]) {
        return;
    }
    const { availableCurrencies } = settings;
    const { supportedCountryCurrency } = settings[paymentMethod];

    if (supportedCountryCurrency && country in supportedCountryCurrency) {
        const requiredCurrency = supportedCountryCurrency[country];
        setConvertCurrency(requiredCurrency);

        if (currency.code === requiredCurrency) {
            setShowCountryIneligible(false);
            setShowCurrencyIneligibleCWOff(false);
            setShowCurrencyIneligibleCWOn(false);
        } else if (availableCurrencies && availableCurrencies.includes(requiredCurrency)) {
            updateCurrencySwitchingInfo(requiredCurrency);
            setShowCountryIneligible(false);
        } else {
            setShowCountryIneligible(false);
            setShowCurrencyIneligibleCWOff(true);
            setShowCurrencyIneligibleCWOn(false);
        }
        return;
    }
    setShowCountryIneligible(true);
    setShowCurrencyIneligibleCWOff(false);
    setShowCurrencyIneligibleCWOn(false);
};

export const airwallexKlarnaOption = {
	name: settings?.name ?? 'airwallex_klarna',
	label: <AirwallexLpmLabel
        title={title}
        icon={icon}
    />,
	content: <AirwallexLpmContent
        settings={settings}
        description={description}
        paymentMethodName={title}
        handleCurrencySwitching={handleCurrencySwitching}
    />,
	edit: <AirwallexLpmContentAdmin
        description={description}
    />,
	canMakePayment: canMakePayment,
	ariaLabel: title,
	supports: {
		features: settings?.supports ?? [],
	}
};
