import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import {
    AirwallexLpmLabel,
    AirwallexLpmContent,
    AirwallexLpmContentAdmin,
} from './elements';
import type { BlockMethodSettings } from '../../types/blocks';

interface KlarnaSettings extends BlockMethodSettings {
	icon?: { url?: string; alt?: string };
}

/**
 * Per-payment-method nested config emitted by
 * `Gateways/Klarna::getLPMMethodScriptData()`. Lives on the outer
 * settings object under the `airwallex_klarna` key (matching
 * `$this->id`), which is why the call site reaches for
 * `settings[paymentMethod]`.
 */
interface KlarnaMethodConfig {
	supportedCountryCurrency?: Record<string, string>;
}

/**
 * Settings shape consumed by `handleCurrencySwitching`. Mirrors the
 * outer `KlarnaSettings` plus the `availableCurrencies` field the WC
 * blocks data payload adds for the currency-switching flow.
 */
interface KlarnaSwitcherSettings extends BlockMethodSettings {
	availableCurrencies?: string[];
}

const settings: KlarnaSettings = getSetting<KlarnaSettings>('airwallex_klarna_data', {});
const icon = settings.icon ?? {};

const title       = settings?.title ?? __('Klarna', 'airwallex-online-payments-gateway');
const description = settings?.description ?? '';

const canMakePayment = (): boolean => {
	return settings?.enabled ?? false;
}

interface SwitcherFnArgs {
	country: string;
	currency: { code: string };
	settings: KlarnaSwitcherSettings;
	paymentMethod: string;
	updateCurrencySwitchingInfo: (requiredCurrency: string) => void;
	setShowEntityIneligible: (v: boolean) => void;
	setShowCountryIneligible: (v: boolean) => void;
	setShowCurrencyIneligibleCWOff: (v: boolean) => void;
	setShowCurrencyIneligibleCWOn: (v: boolean) => void;
	setConvertCurrency: (v: string) => void;
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
}: SwitcherFnArgs): void => {
    if (!settings[paymentMethod]) {
        return;
    }
    const { availableCurrencies } = settings;
    const { supportedCountryCurrency } = settings[paymentMethod] as KlarnaMethodConfig;

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
    void setShowEntityIneligible;
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
