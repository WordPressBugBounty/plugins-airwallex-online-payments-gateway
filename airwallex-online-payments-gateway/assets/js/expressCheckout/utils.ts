const APPLE_PAY_VERSION = 4;

export const maskPageWhileLoading = function (timeout = 5000): void {
	jQuery.blockUI({
		message: null,
		overlayCSS: {
			background: '#fff',
			opacity: 0.6
		}
	});
	setTimeout(function () {
		jQuery.unblockUI();
	}, timeout);
};

export const removePageMask = function (): void {
	jQuery.unblockUI();
};

export const deviceSupportApplePay = (): boolean | undefined => {
	try {
		// `ApplePaySession` is the global from `@types/applepayjs`. The
		// runtime feature-detect mirrors what Safari exposes; assert
		// non-null because the `'ApplePaySession' in window` guard above
		// already proved presence.
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const aps = (window as any).ApplePaySession;
		return (
			'ApplePaySession' in window &&
			aps?.supportsVersion(APPLE_PAY_VERSION) &&
			aps?.canMakePayments()
		);
	} catch {
		console.error('ApplePaySession is not supported on this device.');
	}
};

export const getApplePaySupportedNetworks = (supportBrands: string[] | undefined): string[] => {
	const brands = (supportBrands || [])
		.map((brand) => {
			if (brand === 'unionpay') {
				return 'chinaUnionPay';
			}
			return brand;
		})
		.filter((brand) => brand !== 'diners');
	if (brands.includes('mastercard') && !brands.includes('maestro')) {
		return [...brands, 'maestro'];
	}
	return brands;
};

export const getApplePayMerchantCapabilities = (supportBrands: string[] | undefined): string[] => {
	if (supportBrands?.includes('unionpay')) {
		return ['supports3DS', 'supportsDebit', 'supportsCredit', 'supportsEMV'];
	} else {
		return ['supports3DS', 'supportsDebit', 'supportsCredit'];
	}
};

export const applePayRequiredBillingContactFields: string[] = [
	'email',
	'name',
	'phone',
	'postalAddress',
];

export const applePayRequiredShippingContactFields = (requiresShipping: boolean | undefined): string[] => {
	return requiresShipping ? [
		'email',
		'name',
		'phone',
		'postalAddress',
	] : [
		'email',
		'phone',
	];
};

interface ShippingOption {
	id: string;
	label: string;
	description?: string;
	amount?: number;
}

interface LineItem {
	label: string;
	price: number;
}

export const getGoogleFormattedShippingOptions = (shippingOptions: ShippingOption[]) => {
	return shippingOptions.map((shippingOption) => {
		return {
			id: shippingOption.id,
			label: shippingOption.label,
			description: shippingOption.description,
		};
	});
};

export const getAppleFormattedShippingOptions = (shippingOptions: ShippingOption[]) => {
	return shippingOptions.map((shippingOption) => {
		return {
			identifier: shippingOption.id,
			label: shippingOption.label,
			detail: shippingOption.description,
			amount: shippingOption.amount,
		};
	});
};

export const getAppleFormattedLineItems = (lineItems: LineItem[]) => {
	return lineItems.map((lineItem) => {
		return {
			label: lineItem.label,
			amount: lineItem.price,
		};
	});
};

export const displayLoginConfirmation = (loginConfirmation: { message?: string; redirect_url?: string } | null = null): void => {
	if (!loginConfirmation) {
		return;
	}

	let message = loginConfirmation.message ?? '';

	// Remove asterisks from string.
	message = message.replace(/\*\*/g, '');

	if (confirm(message)) {
		// Redirect to my account page.
		window.location.href = loginConfirmation.redirect_url ?? '';
	}
};

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
