import {getSetting} from '@woocommerce/settings';
import {__} from '@wordpress/i18n';
import {
	AirwallexLpmLabel, AirwallexLpmContent, AirwallexLpmContentAdmin,
} from './elements';
import {useEffect, useState} from 'react';
import type { BlockMethodSettings } from '../../types/blocks';

interface AfterpaySettings extends BlockMethodSettings {
	icon?: { url?: string; alt?: string };
	alterBoxIcons?: { selectArrowIcon?: string };
}

/**
 * Per-payment-method nested config emitted by
 * `Gateways/Afterpay::getLPMMethodScriptData()`. Lives on the outer
 * settings object under the `airwallex_afterpay` key (matching
 * `$this->id`), which is why callers reach for `settings[paymentMethod]`
 * instead of a top-level field.
 */
interface AfterpayMethodConfig {
	supportedCountryCurrency?: Record<string, string>;
	supportedEntityCurrencies?: Record<string, string[]>;
}

/**
 * Settings shape consumed by `handleCurrencySwitching`. The outer
 * envelope mirrors `AfterpaySettings`, plus the entity/currency
 * fields the WC blocks data payload adds for the currency-switching
 * flow. `BlockMethodSettings`'s `[extra: string]: unknown` index
 * signature is what carries `settings[paymentMethod]`.
 */
interface AfterpaySwitcherSettings extends BlockMethodSettings {
	availableCurrencies?: string[];
	owningEntity?: string;
}

const settings: AfterpaySettings = getSetting<AfterpaySettings>('airwallex_afterpay_data', {});
const icon = settings.icon ?? {};

const title = settings?.title ?? __('Afterpay', 'airwallex-online-payments-gateway');
const description = settings?.description ?? '';

const countries = [
	{code: 'US', name: 'United States'},
	{code: 'AU', name: 'Australia'},
	{code: 'NZ', name: 'New Zealand'},
	{code: 'GB', name: 'United Kingdom'},
	{code: 'CA', name: 'Canada'}
];

const afterpayCountryKey = 'airwallex_afterpay_country';

const renderCurrencySwitcher = function (
	supportedCountryCurrency: Record<string, string> | undefined,
	setConvertCurrency: (v: string) => void,
	originalCurrency: string,
	updateCurrencySwitchingInfo: (v: string) => void,
	setShowCurrencyIneligibleCWOn: (v: boolean) => void,
): boolean {
	const selectedCountry = localStorage.getItem(afterpayCountryKey) || '';
	if (!selectedCountry) {
		return false;
	}
	const requiredCurrency = supportedCountryCurrency?.[selectedCountry];
	if (!requiredCurrency) {
		return false;
	}
	setConvertCurrency(requiredCurrency);
	if (originalCurrency !== requiredCurrency) {
		updateCurrencySwitchingInfo(requiredCurrency);
		setShowCurrencyIneligibleCWOn(true);
	} else {
		const CWContainer = document.getElementById('wc-block-airwallex-currency-switching-container');
		if (CWContainer) {
			CWContainer.style.display = 'none';
		}
		setShowCurrencyIneligibleCWOn(false);
	}
	return true;
};

interface CountrySelectorProps {
	paymentMethod: string;
	currency: { code: string };
	setConvertCurrency: (v: string) => void;
	setShowCurrencyIneligibleCWOn: (v: boolean) => void;
	updateCurrencySwitchingInfo: (v: string) => void;
	disablePlaceOrderButton: (v: boolean) => void;
}

const AfterpayCountrySelector = ({
	paymentMethod,
	currency,
	setConvertCurrency,
	setShowCurrencyIneligibleCWOn,
	updateCurrencySwitchingInfo,
	disablePlaceOrderButton,
}: CountrySelectorProps) => {
	const { supportedCountryCurrency } = settings[paymentMethod] as AfterpayMethodConfig;
	const [selectedCountry, setSelectedCountry] = useState<string>(() => {
		return localStorage.getItem(afterpayCountryKey) || '';
	});

	const [dropdownVisible, setDropdownVisible] = useState(false);

	useEffect(() => {
		renderCurrencySwitcher(
			supportedCountryCurrency,
			setConvertCurrency,
			currency.code,
			updateCurrencySwitchingInfo,
			setShowCurrencyIneligibleCWOn,
		);
	}, [selectedCountry]);

	const handleSelect = (code: string) => {
		setSelectedCountry(code);
		localStorage.setItem(afterpayCountryKey, code);
		setDropdownVisible(false);
		disablePlaceOrderButton(false);
	};

	return (<div className="wc-airwallex-afterpay-supported-countries-form">
		<div className="awx-choose-afterpay-region-title">
			{__('Choose your Afterpay account region', 'airwallex-online-payments-gateway')}
		</div>
		<div style={{margin: '10px 0'}}>
			{__('If you don’t have an account yet, choose the region that you will create your account from.', 'airwallex-online-payments-gateway')}
		</div>

		<div className="awx-afterpay-countries">
			<div className="input-icon" onClick={() => setDropdownVisible(!dropdownVisible)}>
				<img src={settings?.alterBoxIcons?.selectArrowIcon} alt="arrow"/>
			</div>
			<div>
				<input
					readOnly
					type="text"
					placeholder={__('Afterpay account region', 'airwallex-online-payments-gateway')}
					value={selectedCountry ? countries.find(c => c.code === selectedCountry)?.name : ''}
					onFocus={() => setDropdownVisible(true)}
					onBlur={() => setTimeout(() => setDropdownVisible(false), 200)}
				/>
			</div>

			{dropdownVisible && (<div className="countries">
				<ul>
					{countries.map((country) => (<li
						key={country.code}
						data-value={country.code}
						className={selectedCountry === country.code ? 'selected' : ''}
						onClick={() => handleSelect(country.code)}
					>
						{country.name}
					</li>))}
				</ul>
			</div>)}
		</div>
	</div>);
};

const canMakePayment = (): boolean => {
	return settings?.enabled ?? false;
}

interface SwitcherFnArgs {
	country: string;
	currency: { code: string };
	settings: AfterpaySwitcherSettings;
	paymentMethod: string;
	updateCurrencySwitchingInfo: (v: string) => void;
	setShowEntityIneligible: (v: boolean) => void;
	setShowCountryIneligible: (v: boolean) => void;
	setShowCurrencyIneligibleCWOff: (v: boolean) => void;
	setShowCurrencyIneligibleCWOn: (v: boolean) => void;
	setConvertCurrency: (v: string) => void;
	setShowCustomPaymentComponent: (v: boolean) => void;
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
	setShowCustomPaymentComponent
}: SwitcherFnArgs): boolean | undefined => {
	if (!settings[paymentMethod]) {
		return;
	}
	const { availableCurrencies, owningEntity } = settings;
	const { supportedCountryCurrency, supportedEntityCurrencies } = settings[paymentMethod] as AfterpayMethodConfig;
	const originalCurrency = currency.code;
	const billingCountry = country;

	setShowCustomPaymentComponent(false);
	setShowEntityIneligible(false);
	setShowCountryIneligible(false);
	setShowCurrencyIneligibleCWOff(false);
	setShowCurrencyIneligibleCWOn(false);

	// `owningEntity` is typed `string | undefined`; runtime gracefully
	// degrades through the `?.` chain when it's missing (yielding
	// `undefined` here and triggering the `!entitySupportedCurrencies`
	// early-return below). The `!` is a type-level assertion only -
	// no runtime behavior changes.
	const entitySupportedCurrencies = supportedEntityCurrencies?.[owningEntity!];
	if (!entitySupportedCurrencies) {
		setShowEntityIneligible(true);
		return false;
	}

	if (!availableCurrencies || !availableCurrencies.length) {
		if (entitySupportedCurrencies.includes(originalCurrency)) {
			return true;
		}
	}

	const billingCurrency = supportedCountryCurrency?.[billingCountry];
	if (entitySupportedCurrencies.includes(originalCurrency) && (owningEntity !== 'AIRWALLEX_HK' || billingCurrency === originalCurrency)) {
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
		setConvertCurrency(requiredCurrency);
		updateCurrencySwitchingInfo(requiredCurrency);
		setShowCurrencyIneligibleCWOn(true);
		return true;
	}

	setShowCustomPaymentComponent(true);
	return renderCurrencySwitcher(
		supportedCountryCurrency,
		setConvertCurrency,
		originalCurrency,
		updateCurrencySwitchingInfo,
		setShowCurrencyIneligibleCWOn,
	);
};

export const airwallexAfterpayOption = {
	name: settings?.name ?? 'airwallex_afterpay',
	label: <AirwallexLpmLabel
		title={title}
		icon={icon}
	/>,
	content: <AirwallexLpmContent
		settings={settings}
		description={description}
		paymentMethodName={title}
		handleCurrencySwitching={handleCurrencySwitching}
		CustomPaymentComponent={AfterpayCountrySelector}
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
