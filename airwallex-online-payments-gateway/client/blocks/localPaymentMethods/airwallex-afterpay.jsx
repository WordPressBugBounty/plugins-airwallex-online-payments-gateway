import {getSetting} from '@woocommerce/settings';
import {__} from '@wordpress/i18n';
import {
	AirwallexLpmLabel, AirwallexLpmContent, AirwallexLpmContentAdmin,
} from './elements';
import {useEffect, useState} from 'react';

const settings = getSetting('airwallex_afterpay_data', {});
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
	supportedCountryCurrency,
	setConvertCurrency,
	originalCurrency,
	updateCurrencySwitchingInfo,
	setShowCurrencyIneligibleCWOn,
) {
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

const AfterpayCountrySelector = ({
	paymentMethod,
	currency,
	setConvertCurrency,
	setShowCurrencyIneligibleCWOn,
	updateCurrencySwitchingInfo,
	disablePlaceOrderButton,
}) => {
	const {supportedCountryCurrency} = settings[paymentMethod];
	const [selectedCountry, setSelectedCountry] = useState(() => {
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

	const handleSelect = (code) => {
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
	setShowCustomPaymentComponent
}) => {
	if (!settings[paymentMethod]) {
		return;
	}
	const {availableCurrencies, owningEntity} = settings;
	const {supportedCountryCurrency, supportedEntityCurrencies} = settings[paymentMethod];
	const originalCurrency = currency.code;
	const billingCountry = country;

	setShowCustomPaymentComponent(false);
	setShowEntityIneligible(false);
	setShowCountryIneligible(false);
	setShowCurrencyIneligibleCWOff(false);
	setShowCurrencyIneligibleCWOn(false);

	const entitySupportedCurrencies = supportedEntityCurrencies?.[owningEntity];
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

	let requiredCurrency;
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