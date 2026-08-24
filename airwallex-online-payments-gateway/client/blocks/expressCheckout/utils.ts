import { getExpressCheckoutData } from "./api";
import type { AllowedCardNetworks } from "../../types/config";

export const APPLE_PAY_VERSION = 4;

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
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const aps = (window as any).ApplePaySession;
		return (
			'ApplePaySession' in window &&
			aps?.supportsVersion(APPLE_PAY_VERSION) &&
			aps?.canMakePayments()
		);
	} catch {
		console.error('ApplePaySession is not supported in iframe');
	}
};

export const applePayRequiredBillingContactFields: string[] = [
	'email',
	'name',
	'phone',
	'postalAddress',
];

export const applePayRequiredShippingContactFields = (requiresShipping: boolean): string[] => {
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

export const getFormattedValueFromBlockAmount = (amount: number | string, currencyMinorUnit: number): number => {
	// google pay only allow 2 digits
	return (parseInt( amount as string, 10 ) / 10 ** currencyMinorUnit);
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

interface ProcessErrorData {
	order_id?: string | number;
}

export const processError = (
	data: ProcessErrorData,
	err: { message?: string },
	removePageMask: () => void,
	onError: (msg: string) => void,
): void => {
	jQuery.ajax({
		url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + data.order_id,
		method: 'GET',
		dataType: 'json',
		success: function(response: { success?: boolean; message?: string }) {
			const errMessage = response.success ? (err.message || '') : response.message;
			removePageMask();
			onError(errMessage as string);
			console.warn(errMessage);
		},
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		error: function(xhr: any) {
			let errMessage: string = xhr.responseText;
			if (xhr.responseJSON && xhr.responseJSON.message) {
				errMessage = xhr.responseJSON.message;
			}
			removePageMask();
			onError(errMessage);
			console.warn(errMessage);
		}
	});
};

/**
 * Response shape from the `wc_ajax_airwallex_get_express_checkout_data`
 * handler. Only the field this function reads
 * (`data.allowedCardNetworks`) is modelled; the runtime envelope is
 * the standard `wp_send_json_success`-style `{ success, data }`.
 */
interface ExpressCheckoutDataResponse {
	success?: boolean;
	data?: { allowedCardNetworks?: AllowedCardNetworks };
}

export const getAllowedCardNetworks = async (): Promise<AllowedCardNetworks | null | undefined> => {
	if (!awxCommonData.getExpressCheckoutData.allowedCardNetworks) {
		try {
			const expressCheckoutData = (await getExpressCheckoutData()) as unknown as ExpressCheckoutDataResponse;
			awxCommonData.getExpressCheckoutData.allowedCardNetworks = expressCheckoutData?.data?.allowedCardNetworks;
		} catch (error) {
			console.error('Failed to get express checkout data:', error);
			return null;
		}
	}
	return awxCommonData.getExpressCheckoutData.allowedCardNetworks;
};
