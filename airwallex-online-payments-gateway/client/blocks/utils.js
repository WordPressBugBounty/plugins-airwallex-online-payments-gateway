export const getCardHolderName = (billingData) => {
	return billingData.first_name.concat(' ', billingData.last_name).trim();
}

export const getBillingInformation = (billingData) => {
	return {
		address: {
			city: billingData.city,
			country_code: billingData.country,
			postcode: billingData.postcode,
			state: billingData.state,
			street: billingData.address_1.concat(' ', billingData.address_2).trim(),
		},
		first_name: billingData.first_name,
		last_name: billingData.last_name,
		email: billingData.email,
	};
}

export const getReplacedText = function(template, values) {
	for (const key in values) {
		template = template.replace(key, values[key]);
	}

	return template;
}

/**
 * Get an unique ID
 * 
 * @returns {String} Unique ID
 */
export const generateUId = () => {
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

export const getBrowserInfo = (sessionId) => {
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

export const getLocaleFromBrowserLanguage = () => {
	const language = navigator.language || navigator.userLanguage;
	const locale   = language.split('-')[0];
	// if locale is zh-HK or zh-TW, return zh-hk
	if (locale === 'zh' && (language === 'zh-HK' || language === 'zh-TW')) {
		return 'zh-HK';
	}

	return locale;
}
