/**
 * `airwallex-common-js` script handle. Registered in
 * `includes/Main.php::registerScripts()` to load at
 * `/assets/js/airwallex-local.js`. Route (a) of the JS-to-TS migration:
 * webpack bundles this `.ts` source and emits back to that same path.
 *
 * Other classic scripts (`airwallex-card`, `airwallex-apm`, etc.)
 * reference `AirwallexClient` as a bare global. Pre-migration the
 * binding leaked into shared classic-script lexical scope; post-bundle
 * it must be explicitly attached to `window` (last line of the file)
 * so consumers see the same global surface.
 */

const AirwallexClientImpl = {
	getCustomerInformation: function (fieldId: string, parameterName: string): string {
		const $inputField = jQuery('#' + fieldId);
		if ($inputField.length) {
			return ($inputField.val() as string).toString().trim();
		} else if (typeof (awxCommonData as Record<string, unknown>)[parameterName] !== 'undefined') {
			return String((awxCommonData as Record<string, unknown>)[parameterName]).trim();
		} else {
			return '';
		}
	},
	getCardHolderName: function (): string {
		return String(AirwallexClientImpl.getCustomerInformation('billing_first_name', 'billingFirstName') + ' ' + AirwallexClientImpl.getCustomerInformation('billing_last_name', 'billingLastName')).trim();
	},
	getBillingInformation: function () {
		return {
			address: {
				city: AirwallexClientImpl.getCustomerInformation('billing_city', 'billingCity'),
				country_code: AirwallexClientImpl.getCustomerInformation('billing_country', 'billingCountry'),
				postcode: AirwallexClientImpl.getCustomerInformation('billing_postcode', 'billingPostcode'),
				state: AirwallexClientImpl.getCustomerInformation('billing_state', 'billingState'),
				street: String(AirwallexClientImpl.getCustomerInformation('billing_address_1', 'billingAddress1') + ' ' + AirwallexClientImpl.getCustomerInformation('billing_address_2', 'billingAddress2')).trim(),
			},
			first_name: AirwallexClientImpl.getCustomerInformation('billing_first_name', 'billingFirstName'),
			last_name: AirwallexClientImpl.getCustomerInformation('billing_last_name', 'billingLastName'),
			email: AirwallexClientImpl.getCustomerInformation('billing_email', 'billingEmail'),
		}
	},
	ajaxGet: function (url: string, callback: (data: unknown) => void): void {
		const xmlhttp              = new XMLHttpRequest();
		xmlhttp.onreadystatechange = function () {
			if (xmlhttp.readyState === 4 && xmlhttp.status === 200) {
				let data: unknown;
				try {
					data = JSON.parse(xmlhttp.responseText);
				} catch (err) {
					console.log((err as Error).message + " in " + xmlhttp.responseText);
					return;
				}
				callback(data);
			}
		};
		xmlhttp.open("GET", url, true);
		xmlhttp.send();
	},
	displayCheckoutError: function (form: string | HTMLElement, msg: string): void {
		// jQuery's overloads don't accept the `string | HTMLElement` union
		// directly; cast through one of the variants. Runtime accepts both.
		const checkout_form: JQuery = jQuery(form as string);
		jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
		if (msg.indexOf('class="woocommerce-error"') === -1) {
			msg = "<ul class=\"woocommerce-error\"><li>" + msg + "</li></ul>";
		}
		checkout_form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + msg + '</div>');
		checkout_form.removeClass('processing').unblock();
		checkout_form.find('.input-text, select, input:checkbox').trigger('validate').blur();
		let scrollElement: JQuery = jQuery('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');

		if (!scrollElement.length) {
			scrollElement = checkout_form;
		}
		if (typeof jQuery.scroll_to_notices === 'function') {
			jQuery.scroll_to_notices(scrollElement);
		}
	}
};

// hide the express checkout gateway in the payment options
jQuery(document.body).on('updated_checkout', function () {
	jQuery('.payment_method_airwallex_express_checkout').hide();
});

// Expose the singleton globally so other classic-script bundles
// (airwallex-card, airwallex-apm, …) keep their bare-global access
// post-bundle. See file header for rationale.
window.AirwallexClient = AirwallexClientImpl;

export {};
