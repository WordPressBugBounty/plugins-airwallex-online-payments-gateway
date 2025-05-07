/* global awxAdminSettings, awxAdminECSettings */
jQuery(function ($) {
	'use strict';

	const googlePayJSLib       = 'https://pay.google.com/gp/p/js/pay.js';
	const applePayJSLib        = 'https://applepay.cdn-apple.com/jsapi/v1.1.0/apple-pay-sdk.js';
	const awxGoogleBaseRequest = {
		apiVersion: 2,
		apiVersionMinor: 0
	};

	const awxGoogleAllowedCardNetworks    = ["MASTERCARD", "VISA"];
	const awxGoogleAllowedCardAuthMethods = ["PAN_ONLY", "CRYPTOGRAM_3DS"];
	const awxGoogleBaseCardPaymentMethod  = {
		type: 'CARD',
		parameters: {
			allowedAuthMethods: awxGoogleAllowedCardAuthMethods,
			allowedCardNetworks: awxGoogleAllowedCardNetworks,
		}
	};

	const airwallexExpressCheckoutSettings = {
		googlePaymentsClient: null,

		init: function () {
			if (!awxAdminECSettings) {
				return;
			}

			$('.wc-awx-ec-domain-file-host-path').html(
				$('.wc-awx-ec-domain-file-host-path').html().replace('$domain_name$', window.location.origin)
			);

			this.registerCustomizeEventListener();

			const appleScript  = document.createElement('script');
			appleScript.src    = applePayJSLib;
			appleScript.async  = true;
			appleScript.onload = () => {
				if (window.ApplePaySession) {
					$('.awx-apple-pay-btn').show();
				}
			};
			document.body.appendChild(appleScript);

			const googleScript  = document.createElement('script');
			googleScript.src    = googlePayJSLib;
			googleScript.async  = true;
			googleScript.onload = () => {
				airwallexExpressCheckoutSettings.onGooglePayLoaded();
				$('.awx-google-pay-btn').show();
			};
			document.body.appendChild(googleScript);
		},

		registerCustomizeEventListener: function() {
			$('#airwallex-online-payments-gatewayairwallex_express_checkout_call_to_action').change(function() {
				awxAdminECSettings.buttonType = this.value;
				airwallexExpressCheckoutSettings.reloadGooglePayButton();
				airwallexExpressCheckoutSettings.reloadApplePayButton();
				airwallexExpressCheckoutSettings.setButtonHeight();
			});

			$('#airwallex-online-payments-gatewayairwallex_express_checkout_appearance_size').change(function() {
				awxAdminECSettings.size = this.value;
				airwallexExpressCheckoutSettings.setButtonHeight();
			});

			$('#airwallex-online-payments-gatewayairwallex_express_checkout_appearance_theme').change(function() {
				awxAdminECSettings.theme = this.value;
				airwallexExpressCheckoutSettings.reloadGooglePayButton();
				airwallexExpressCheckoutSettings.reloadApplePayButton();
				airwallexExpressCheckoutSettings.setButtonHeight();
			});
		},

		setButtonHeight: function() {
			const height = awxAdminECSettings.sizeMap[awxAdminECSettings.size];
			$('.awx-apple-pay-btn apple-pay-button').css('--apple-pay-button-height', height);
			$('.awx-google-pay-btn button').css('height', height);
		},

		reloadApplePayButton: function() {
			$('.awx-apple-pay-btn').empty();
			$('.awx-apple-pay-btn').append(
				$('<apple-pay-button>').attr('locale', awxAdminECSettings.locale)
					.attr('buttonstyle', awxAdminECSettings.theme)
					.attr('type', awxAdminECSettings.buttonType)
			);
		},

		reloadGooglePayButton: function() {
			$('.awx-google-pay-btn').empty();
			this.addGooglePayButton();
		},

		addGooglePayButton: function() {
			const client = this.getGooglePaymentsClient();
			const button = client.createButton({
				buttonColor: awxAdminECSettings.theme,
				buttonType: awxAdminECSettings.buttonType,
				buttonSizeMode: 'fill',
				onClick: () => {},
			});
			$('.awx-google-pay-btn').append(button);
		},

		getGooglePaymentsClient: function() {
			if ( this.googlePaymentsClient === null ) {
				this.googlePaymentsClient = new google.payments.api.PaymentsClient({
					environment: "TEST",
				});
			}

			return this.googlePaymentsClient;
		
		},

		getGoogleIsReadyToPayRequest: function() {
			return Object.assign(
				{},
				awxGoogleBaseRequest,
				{
					allowedPaymentMethods: [awxGoogleBaseCardPaymentMethod]
				}
			);
		},

		onGooglePayLoaded: function() {
			const client = this.getGooglePaymentsClient();
			client.isReadyToPay(this.getGoogleIsReadyToPayRequest())
				.then(function(response) {
					if (response.result) {
						airwallexExpressCheckoutSettings.reloadGooglePayButton();
						airwallexExpressCheckoutSettings.setButtonHeight();
					}
				})
				.catch(function(err) {
					console.error(err);
				});
		},
	};

	airwallexExpressCheckoutSettings.init();

	const handlePaymentMethodActivationFailure = function (ele, errorCode) {
		ele.prop('checked', false);
		switch (errorCode) {
			case 'payment_method_not_activated':
				ele.closest('div').find('.wc-awx-checkbox-error-icon').show();
				$(`.wc-awx-ec-payment-method-${ele.val()}-not-enabled`).show();
				break;
			case 'domain_file_upload_error':
				ele.closest('div').find('.wc-awx-checkbox-error-icon').show();
				$(`.wc-awx-ec-${ele.val()}-add-domain-file-failed`).show();
				break;
			case 'domain_registration_error':
				ele.closest('div').find('.wc-awx-checkbox-error-icon').show();
				$(`.wc-awx-ec-${ele.val()}-domain-registration-failed`).show();
				break;
			default:
				break;
		}
	}

	$('.wc-awx-express-checkout-payment-method').on('change', function() {
		$('.wc-awx-checkbox-error-icon').hide();
		$('.wc-awx-checkbox-error-message').hide();
		if (!this.checked) return;

		const me = $(this);
		me.prop('disabled', true);
		const spinner = me.closest('div').find('.wc-awx-checkbox-spinner');
		spinner.show();
		$.ajax({
			type: 'POST',
			data: {
				payment_method_type: me.val().replace('_', ''),
				security: awxAdminECSettings.apiSettings.nonce.activatePaymentMethod,
				domain_name: window.location.host,
			},
			url: awxAdminECSettings.apiSettings.ajaxUrl.activatePaymentMethod,
		}).done(function (response) {
			spinner.hide();
			me.prop('disabled', false);
			if (!response.success) {
				handlePaymentMethodActivationFailure(me, response?.error?.code);
			}
		}).fail(function (error) {
			spinner.hide();
			me.prop('disabled', false);
			console.log(error);
			handlePaymentMethodActivationFailure(me, error?.code);
		});
	});

    if (awxAdminSettings && awxAdminSettings.apiSettings.connected) {
        $('.wc-airwallex-connection-test').closest('tr').hide();
        $('#awx-account-not-connected').hide();
		$('#awx-account-connected').show();
    }

	$('.wc-airwallex-client-id, .wc-airwallex-api-key, .wc-airwallex-sandbox').on('change', function() {
        $('.wc-airwallex-connection-test').closest('tr').show();
		$('#awx-account-not-connected').hide();
		$('#awx-account-connected').hide();
	});

	const airwallexConnectionFlow = {
		init: function() {
			if ($('.airwallex_general').length === 0) {
				return;
			}
			// move the fallback buttons to the correct position
			airwallexConnectionFlow.moveFallbackButtons();
			// move the connection failed alert under the enable sandbox checkbox
			airwallexConnectionFlow.moveConnectionFailedAlert();
			airwallexConnectionFlow.displayAlert();
			airwallexConnectionFlow.displayConnectionFailedAlert();
			airwallexConnectionFlow.toggleConnectedAccount();
			airwallexConnectionFlow.toggleConnectViaAPIKey();
			$('.wc-airwallex-connect-button').on('click', function(e) {
				e.preventDefault();
				const env = airwallexConnectionFlow.getEnv();
				if (env === 'prod' && $('#awx-account-connected').is(':visible') && awxAdminSettings.apiSettings.connectedViaApiKey) {
					airwallexConnectionFlow.showConnectViaAPIKey();
					airwallexConnectionFlow.hideProdConnectedAlert();
				} else {
					airwallexConnectionFlow.startConnectionFlow();
				}
			});

			// check for the connection status when the enable sandbox checkbox is toggled
			$('#airwallex-online-payments-gatewayairwallex_general_enable_sandbox').on('change', function() {
				airwallexConnectionFlow.testConnection();
			});

			$('.wc-airwallex-connect-api-key-button').on('click', function(e) {
				e.preventDefault();
				// to save the form if user does not change the field, trigger change first otherwise the save button is disabled
				$('#airwallex-online-payments-gatewayairwallex_general_client_id').trigger('change');
				$('.woocommerce-save-button').trigger('click');
			});

			$('.wc-airwallex-connect-flow-button').on('click', function(e) {
				e.preventDefault();
				airwallexConnectionFlow.startConnectionFlow();
			});

			$('.wc-airwallex-connect-cancel-button').on('click', function(e) {
				e.preventDefault();
				airwallexConnectionFlow.hideConnectViaAPIKey();
				airwallexConnectionFlow.showProdConnectedAlert();
			});
		},

		moveFallbackButtons: function() {
			$('#wc-airwallex-connect-api-key-buttons').insertAfter($('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('fieldset'));
		},

		moveConnectionFailedAlert: function() {
			$('.wc-airwallex-connection-failed').insertAfter($('.form-table tr:first'));
		},

		startConnectionFlow: function() {
			$.ajax({
				type: 'POST',
				data: {
					security: awxAdminSettings.apiSettings.nonce.startConnectionFlow,
					env: airwallexConnectionFlow.getEnv(),
				},
				url: awxAdminSettings.apiSettings.ajaxUrl.startConnectionFlow,
			}).done(function (response) {
				if (response.success) {
					window.onbeforeunload = '';
					$(window).off('beforeunload');
					location.href = response.redirect_url;
				} else {
					window.alert('Failed to connect account. ' + response.message);
				}
			}).fail(function (error) {
				console.log(error);
				window.alert('Failed to connect account.');
			});
		},

		displayAlert: function() {
			$('.wc-airwallex-connection-alert').hide();
			const env = airwallexConnectionFlow.getEnv();
			if ('prod' === env) {
				if ($('#awx-account-connected').is(':visible')) {
					$('.wc-airwallex-account-connected').show();
				} else {
					$('.wc-airwallex-account-not-connected').show();
				}
			} else {
				if ($('#awx-account-connected').is(':visible')) {
					$('.wc-airwallex-demo-account-connected').show();
				} else {
					$('.wc-airwallex-demo-account-not-connected').show();
				}
			}
		},

		displayConnectionFailedAlert: function() {
			const env = airwallexConnectionFlow.getEnv();
			if ('prod' === env && awxAdminSettings.apiSettings.connectionFailed) {
				$('.wc-airwallex-connection-alert').hide();
				$('.wc-airwallex-connection-failed').show();
			}
		},

		testConnection: function() {
			const ele = $('#airwallex-online-payments-gatewayairwallex_general_enable_sandbox');
			airwallexConnectionFlow.toggleLoadingSpinner(ele, true);
			const env = ele.length ? (ele.is(':checked') ? 'demo' : 'prod') : '';
			$.ajax({
				type: 'POST',
				data: {
					security: awxAdminSettings.apiSettings.nonce.connectionTest,
					env,
				},
				url: awxAdminSettings.apiSettings.ajaxUrl.connectionTest,
			}).done(function (response) {
				airwallexConnectionFlow.toggleConnected(response.success);
				airwallexConnectionFlow.displayAlert();
				airwallexConnectionFlow.displayConnectionFailedAlert();
				airwallexConnectionFlow.toggleConnectViaAPIKey();
				airwallexConnectionFlow.toggleLoadingSpinner(ele, false);
				airwallexConnectionFlow.toggleConnectedAccount();
			}).fail(function (error) {
				console.log(error);
				airwallexConnectionFlow.displayAlert();
				airwallexConnectionFlow.toggleLoadingSpinner(ele, false);
				airwallexConnectionFlow.toggleConnectedAccount();
			});
		},

		toggleConnected: function(connected) {
			if (connected) {
				$('#awx-account-not-connected').hide();
				$('#awx-account-connected').show();
			} else {
				$('#awx-account-not-connected').show();
				$('#awx-account-connected').hide();
			}
		},

		toggleLoadingSpinner: function(ele, showSpinner) {
			ele.prop('disabled', showSpinner);
			if (showSpinner) {
				ele.closest('label').append('<span class="wc-awx-checkbox-spinner"></span>');
				ele.closest('label').find('.wc-awx-checkbox-spinner').css('display', 'inline-block');
			} else {
				ele.closest('label').find('.wc-awx-checkbox-spinner').remove();
			}
		},

		toggleConnectedAccount: function() {
			const env = airwallexConnectionFlow.getEnv();
			if ('prod' === env) {
				$('.wc-airwallex-account-name').text(awxAdminSettings.apiSettings.accountName.prod);
			} else {
				$('.wc-airwallex-account-name').text(awxAdminSettings.apiSettings.accountName.demo);
			}
			if ($('#awx-account-connected').is(':visible')) {
				$('.wc-airwallex-connect-button-label').text(awxAdminSettings.apiSettings.connectButtonText.manage);
			} else {
				$('.wc-airwallex-connect-button-label').text(awxAdminSettings.apiSettings.connectButtonText.connect);
			}
		},

		toggleConnectViaAPIKey: function() {
			const env = airwallexConnectionFlow.getEnv();
			if ('prod' === env && awxAdminSettings.apiSettings.connectionFailed) {
				$('.wc-airwallex-connect-button').closest('tr').hide();
				$('#airwallex-online-payments-gatewayairwallex_general_client_id').closest('tr').show();
				$('#airwallex-online-payments-gatewayairwallex_general_api_key').closest('tr').show();
				$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('tr').show();
				$('#wc-airwallex-connect-api-key-buttons').show();
			} else {
				$('.wc-airwallex-connect-button').closest('tr').show();
				$('#airwallex-online-payments-gatewayairwallex_general_client_id').closest('tr').hide();
				$('#airwallex-online-payments-gatewayairwallex_general_api_key').closest('tr').hide();
				$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('tr').hide();
				$('#wc-airwallex-connect-api-key-buttons').hide();
			}
		},

		

		showConnectViaAPIKey: function() {
			$('#airwallex-online-payments-gatewayairwallex_general_client_id').closest('tr').show();
			$('#airwallex-online-payments-gatewayairwallex_general_api_key').closest('tr').show();
			$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('tr').show();
			$('#wc-airwallex-connect-api-key-buttons').show();
			$('.wc-airwallex-connect-cancel-button').show();
			$('.wc-airwallex-connect-button').closest('tr').hide();
		},

		hideConnectViaAPIKey: function() {
			$('#airwallex-online-payments-gatewayairwallex_general_client_id').closest('tr').hide();
			$('#airwallex-online-payments-gatewayairwallex_general_api_key').closest('tr').hide();
			$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('tr').hide();
			$('#wc-airwallex-connect-api-key-buttons').hide();
			$('.wc-airwallex-connect-button').closest('tr').show();
		},

		showProdConnectedAlert: function() {
			$('.wc-airwallex-connection-alert.wc-airwallex-account-connected').show();
		},

		hideProdConnectedAlert: function() {
			$('.wc-airwallex-connection-alert.wc-airwallex-account-connected').hide();
		},

		getEnv: function() {
			return $('#airwallex-online-payments-gatewayairwallex_general_enable_sandbox').is(':checked') ? 'demo' : 'prod';
		}
	};

	airwallexConnectionFlow.init();

	const saveCardEnableSelector = '#airwallex-online-payments-gatewayairwallex_card_save_card_enabled';
	const toggleCVCField = function() {
		let selector = '#airwallex-online-payments-gatewayairwallex_card_skip_cvc_enabled';
		if ($(saveCardEnableSelector).prop('checked')) {
			$(selector).closest('tr').show();
		} else {
			$(selector).closest('tr').hide();
		}
	}
	toggleCVCField();
	$(saveCardEnableSelector).on('change', toggleCVCField);
});
