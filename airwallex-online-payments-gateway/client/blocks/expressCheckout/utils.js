import { getExpressCheckoutData } from "./api";

export const APPLE_PAY_VERSION = 4;

export const maskPageWhileLoading = function (timeout = 5000) {
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

export const removePageMask = function () {
	jQuery.unblockUI();
};

export const deviceSupportApplePay = () => {
	try {
		return (
			'ApplePaySession' in window &&
			ApplePaySession?.supportsVersion(APPLE_PAY_VERSION) &&
			ApplePaySession?.canMakePayments()
		);
	} catch {
		console.error('ApplePaySession is not supported in iframe');
	}
};

export const applePayRequiredBillingContactFields = [
	'email',
	'name',
	'phone',
	'postalAddress',
];

export const applePayRequiredShippingContactFields = (requiresShipping) => {
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

export const getGoogleFormattedShippingOptions = (shippingOptions) => {
	return shippingOptions.map((shippingOption) => {
		return {
			id: shippingOption.id,
			label: shippingOption.label,
			description: shippingOption.description,
		};
	});
};

export const getAppleFormattedShippingOptions = (shippingOptions) => {
	return shippingOptions.map((shippingOption) => {
		return {
			identifier: shippingOption.id,
			label: shippingOption.label,
			detail: shippingOption.description,
			amount: shippingOption.amount,
		};
	});
};

export const getAppleFormattedLineItems = (lineItems) => {
	return lineItems.map((lineItem) => {
		return {
			label: lineItem.label,
			amount: lineItem.price,
		};
	});
};

export const getFormattedValueFromBlockAmount = (amount, currencyMinorUnit) => {
	// google pay only allow 2 digits
	return (parseInt( amount, 10 ) / 10 ** currencyMinorUnit);
};

export const displayLoginConfirmation = (loginConfirmation = null) => {
	if (!loginConfirmation) {
		return;
	}

	let message = loginConfirmation.message;

	// Remove asterisks from string.
	message = message.replace(/\*\*/g, '');

	if (confirm(message)) {
		// Redirect to my account page.
		window.location.href = loginConfirmation.redirect_url;
	}
};

export const processError = (data, err, removePageMask, onError) => {
	jQuery.ajax({
		url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + data.order_id,
		method: 'GET',
		dataType: 'json',
		success: function(response) {
			let errMessage = response.success ? (err.message || '') : response.message;
			removePageMask();
			onError(errMessage);
			console.warn(errMessage);
		},
		error: function(xhr, status, error) {
			let errMessage = xhr.responseText;
			if (xhr.responseJSON && xhr.responseJSON.message) {
				errMessage = xhr.responseJSON.message;
			}
			removePageMask();
			onError(errMessage);
			console.warn(errMessage); 
		}
	});
};

export const getAllowedCardNetworks = async () => {
	if (!awxCommonData.getExpressCheckoutData.allowedCardNetworks) {
		try {
			const expressCheckoutData = await getExpressCheckoutData();
			awxCommonData.getExpressCheckoutData.allowedCardNetworks = expressCheckoutData?.data?.allowedCardNetworks;
		} catch (error) {
			console.error('Failed to get express checkout data:', error);
			return null;
		}
	}
	return awxCommonData.getExpressCheckoutData.allowedCardNetworks;
};
