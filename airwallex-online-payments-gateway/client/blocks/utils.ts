// All fields optional so partial test fixtures still satisfy the type.
// Production callers pass the full WC `billing.billingData` shape;
// missing fields would throw at runtime today (e.g. `.concat` on
// undefined) - identical to pre-Phase-C behavior, just unannotated.
export interface BillingData {
	first_name?: string;
	last_name?: string;
	city?: string;
	country?: string;
	postcode?: string;
	state?: string;
	address_1?: string;
	address_2?: string;
	email?: string;
}

export const getCardHolderName = (billingData: BillingData): string => {
	return (billingData.first_name as string).concat(' ', billingData.last_name as string).trim();
}

export const getBillingInformation = (billingData: BillingData) => {
	return {
		address: {
			city: billingData.city,
			country_code: billingData.country,
			postcode: billingData.postcode,
			state: billingData.state,
			street: (billingData.address_1 as string).concat(' ', billingData.address_2 as string).trim(),
		},
		first_name: billingData.first_name,
		last_name: billingData.last_name,
		email: billingData.email,
	};
}

export const getReplacedText = function(template: string, values: Record<string, string>): string {
	for (const key in values) {
		template = template.split(key).join(values[key]);
	}

	return template;
}

/**
 * Get an unique ID
 *
 * @returns {String} Unique ID
 */
export const generateUId = (): string => {
	const uniqueId       = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		const r          = (Math.random() * 16) | 0;
		const v          = c === 'x' ? r : (r & 0x3) | 0x8;
		return v.toString(16);
	});
	return uniqueId;
};

export let airTrackerCommonData = {
	sessionId: generateUId(),
};

export const getBrowserInfo = (sessionId: string | null | undefined) => {
	const { navigator, screen } = window || {};
	const { language, userAgent } = navigator || {};
	const { colorDepth, height, width } = screen || {};

	return {
		device_id: sessionId,
		screen_height: height,
		screen_width: width,
		screen_color_depth: colorDepth,
		language: language,
		timezone: new Date().getTimezoneOffset(),
		browser: {
			java_enabled: navigator?.javaEnabled(),
			javascript_enabled: true,
			user_agent: userAgent,
		},
	};
}

export const getLocaleFromBrowserLanguage = (): string => {
	const language = navigator.language || navigator.userLanguage || '';
	const locale   = language.split('-')[0];
	// if locale is zh-HK or zh-TW, return zh-hk
	if (locale === 'zh' && (language === 'zh-HK' || language === 'zh-TW')) {
		return 'zh-HK';
	}

	return locale;
}

export const getSessionId = (): string | null | undefined => {
	return document.getElementById('airwallex-fraud-api')?.getAttribute('data-order-session-id');
}

export const getSupportedNetworksForApplePay = (supportBrands: string[]): string[] => {
	const brands = supportBrands.map(function (brand) {
			if (brand === 'unionpay') {
				return 'chinaUnionPay';
			}
			if (brand === 'mastercard') {
				return 'masterCard';
			}
			return brand;
		}).filter(function (brand) {
			return brand !== 'diners';
		});

	if (brands.indexOf('masterCard') !== -1 && brands.indexOf('maestro') === -1) {
		brands.push('maestro');
	}

	return brands;
}

export const getSupportedNetworksForGooglePay = (supportBrands: string[]): string[] => {
	const brands = supportBrands.map(function (brand) {
		return brand.toUpperCase();
	}).filter(function (brand) {
		return brand !== 'UNIONPAY' && brand !== 'MAESTRO' && brand !== 'DINERS';
	});
	return brands;
}
