/* global awxAdminSettings, awxAdminECSettings */
import type { ApmDataResponse, POSTerminalsResponse } from '../../../client/types/api';

jQuery(function ($) {
	'use strict';

	const googlePayJSLib       = 'https://pay.google.com/gp/p/js/pay.js';
	const applePayJSLib        = 'https://applepay.cdn-apple.com/jsapi/v1.1.0/apple-pay-sdk.js';
	const awxGoogleBaseRequest = {
		apiVersion: 2,
		apiVersionMinor: 0
	};

	const awxGoogleAllowedCardNetworks: google.payments.api.CardNetwork[]       = ["MASTERCARD", "VISA"];
	const awxGoogleAllowedCardAuthMethods: google.payments.api.CardAuthMethod[] = ["PAN_ONLY", "CRYPTOGRAM_3DS"];
	const awxGoogleBaseCardPaymentMethod: google.payments.api.IsReadyToPayPaymentMethodSpecification = {
		type: 'CARD',
		parameters: {
			allowedAuthMethods: awxGoogleAllowedCardAuthMethods,
			allowedCardNetworks: awxGoogleAllowedCardNetworks,
		}
	};

	// Pattern B object literal: methods reference `this.foo` to call
	// siblings. Each method declares an explicit `this` parameter so
	// the receiver is preserved when TypeScript checks the call sites.
	// `airwallexExpressCheckoutSettings.X()` calls always go through
	// the named binding (line 144 below and inside the .then() callback
	// on line 134); never modernise to `this.X()` outside a method body.
	interface AwxAdminECImpl {
		googlePaymentsClient: google.payments.api.PaymentsClient | null;
		init(this: AwxAdminECImpl): void;
		registerCustomizeEventListener(this: AwxAdminECImpl): void;
		setButtonHeight(this: AwxAdminECImpl): void;
		reloadApplePayButton(this: AwxAdminECImpl): void;
		reloadGooglePayButton(this: AwxAdminECImpl): void;
		addGooglePayButton(this: AwxAdminECImpl): void;
		getGooglePaymentsClient(this: AwxAdminECImpl): google.payments.api.PaymentsClient;
		getGoogleIsReadyToPayRequest(this: AwxAdminECImpl): google.payments.api.IsReadyToPayRequest;
		onGooglePayLoaded(this: AwxAdminECImpl): void;
	}

	const airwallexExpressCheckoutSettings: AwxAdminECImpl = {
		googlePaymentsClient: null,

		init: function (this: AwxAdminECImpl) {
			if (!awxAdminECSettings) {
				return;
			}

			$('.wc-awx-ec-domain-file-host-path').html(
				($('.wc-awx-ec-domain-file-host-path').html() as string).replace('$domain_name$', window.location.origin)
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

		// Pattern A: the inner `function(this: HTMLElement) {}` keeps
		// DOM `this` (the changed element); do not convert to arrow.
		// Cast to `HTMLInputElement` inside to read `.value` because
		// jQuery's TypeEventHandler signature pins the receiver to
		// `HTMLElement`, not the more specific subclass.
		registerCustomizeEventListener: function() {
			$('#airwallex-online-payments-gatewayairwallex_express_checkout_call_to_action').change(function(this: HTMLElement) {
				awxAdminECSettings.buttonType = (this as HTMLInputElement).value;
				airwallexExpressCheckoutSettings.reloadGooglePayButton();
				airwallexExpressCheckoutSettings.reloadApplePayButton();
				airwallexExpressCheckoutSettings.setButtonHeight();
			});

			$('#airwallex-online-payments-gatewayairwallex_express_checkout_appearance_size').change(function(this: HTMLElement) {
				awxAdminECSettings.size = (this as HTMLInputElement).value;
				airwallexExpressCheckoutSettings.setButtonHeight();
			});

			$('#airwallex-online-payments-gatewayairwallex_express_checkout_appearance_theme').change(function(this: HTMLElement) {
				awxAdminECSettings.theme = (this as HTMLInputElement).value;
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

		reloadGooglePayButton: function(this: AwxAdminECImpl) {
			$('.awx-google-pay-btn').empty();
			this.addGooglePayButton();
		},

		addGooglePayButton: function(this: AwxAdminECImpl) {
			const client = this.getGooglePaymentsClient();
			// `theme` / `buttonType` are wp_localize_script payload fields
			// (string in `AdminECSettings`); the GP SDK constrains them to
			// narrower literal unions, so narrow them once into locals
			// rather than reshape the inline-script contract.
			const buttonColor = awxAdminECSettings.theme as google.payments.api.ButtonColor;
			const buttonType = awxAdminECSettings.buttonType as google.payments.api.ButtonType;
			const button = client.createButton({
				buttonColor,
				buttonType,
				buttonSizeMode: 'fill',
				onClick: () => { /* noop */ },
			});
			$('.awx-google-pay-btn').append(button);
		},

		getGooglePaymentsClient: function(this: AwxAdminECImpl) {
			if ( this.googlePaymentsClient === null ) {
				// `google.payments.api.PaymentsClient` is provided at runtime
				// by the Google Pay JS SDK loaded in `init()`; its type comes
				// from the ambient `@types/googlepay` package.
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

		// `airwallexExpressCheckoutSettings.X` (named binding) is used
		// inside the `.then(function(response) { ... })` because the
		// inner `function` would lose `this` to `undefined`. Do not
		// rewrite to `this.X()` here. See migration plan Pattern B.
		onGooglePayLoaded: function(this: AwxAdminECImpl) {
			const client = this.getGooglePaymentsClient();
			client.isReadyToPay(this.getGoogleIsReadyToPayRequest())
				.then(function(response: { result?: boolean }) {
					if (response.result) {
						airwallexExpressCheckoutSettings.reloadGooglePayButton();
						airwallexExpressCheckoutSettings.setButtonHeight();
					}
				})
				.catch(function(err: unknown) {
					console.error(err);
				});
		},
	};

	airwallexExpressCheckoutSettings.init();

	const handlePaymentMethodActivationFailure = function (ele: JQuery, errorCode: string | undefined): void {
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

	$('.wc-awx-express-checkout-payment-method').on('change', function(this: HTMLElement) {
		$('.wc-awx-checkbox-error-icon').hide();
		$('.wc-awx-checkbox-error-message').hide();
		if (!(this as HTMLInputElement).checked) return;

		const me = $(this);
		me.prop('disabled', true);
		const spinner = me.closest('div').find('.wc-awx-checkbox-spinner');
		spinner.show();
		$.ajax({
			type: 'POST',
			data: {
				payment_method_type: (me.val() as string).replace('_', ''),
				security: awxAdminECSettings.apiSettings.nonce.activatePaymentMethod,
				domain_name: window.location.host,
			},
			url: awxAdminECSettings.apiSettings.ajaxUrl.activatePaymentMethod,
		}).done(function (response: { success?: boolean; error?: { code?: string } }) {
			spinner.hide();
			me.prop('disabled', false);
			if (!response.success) {
				handlePaymentMethodActivationFailure(me, response?.error?.code);
			}
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
		}).fail(function (error: any) {
			spinner.hide();
			me.prop('disabled', false);
			console.log(error);
			handlePaymentMethodActivationFailure(me, error?.code);
		});
	});

    if (awxAdminSettings?.apiSettings?.connected) {
        $('.wc-airwallex-connection-test').closest('tr').hide();
        $('#awx-account-not-connected').hide();
		$('#awx-account-connected').show();
    }

	$('.wc-airwallex-client-id, .wc-airwallex-api-key, .wc-airwallex-sandbox').on('change', function() {
        $('.wc-airwallex-connection-test').closest('tr').show();
		$('#awx-account-not-connected').hide();
		$('#awx-account-connected').hide();
	});

	// Pattern B object literal — same conventions as
	// `airwallexExpressCheckoutSettings` above. Methods using `this.foo`
	// declare an explicit `this:` parameter; named-binding calls
	// (`airwallexConnectionFlow.bar()`) outside method bodies stay as
	// named bindings.
	interface AwxAdminConnectionFlow {
		init(this: AwxAdminConnectionFlow): void;
		moveConnectionFailedAlert(): void;
		startConnectionFlow(): void;
		displayAlert(): void;
		displayConnectionFailedAlert(): void;
		testConnection(): void;
		toggleConnected(connected: boolean): void;
		toggleLoadingSpinner(ele: JQuery, showSpinner: boolean): void;
		toggleConnectedAccount(): void;
		initializeCheckboxState(): void;
		toggleConnectionMethod(): void;
		showApiKeyFields(): void;
		showConnectButton(): void;
		connectViaApiKey(): void;
		showProdConnectedAlert(): void;
		hideProdConnectedAlert(): void;
		getEnv(): 'sandbox' | 'prod';
		updateCredentialFields(): void;
	}

	const airwallexConnectionFlow: AwxAdminConnectionFlow = {
		init: function() {
			if ($('.airwallex_general').length === 0) {
				return;
			}
			// move the connection failed alert under the enable sandbox checkbox
			airwallexConnectionFlow.moveConnectionFailedAlert();
			airwallexConnectionFlow.displayAlert();
			airwallexConnectionFlow.displayConnectionFailedAlert();
			airwallexConnectionFlow.toggleConnectedAccount();

			airwallexConnectionFlow.initializeCheckboxState();
			airwallexConnectionFlow.toggleConnectionMethod();

			$('.wc-airwallex-use-api-key-checkbox').on('change', function() {
				airwallexConnectionFlow.toggleConnectionMethod();
			});

			$('.wc-airwallex-connect-button').on('click', function(e: JQuery.ClickEvent) {
				e.preventDefault();
				airwallexConnectionFlow.startConnectionFlow();
			});

			$('#airwallex-online-payments-gatewayairwallex_general_enable_sandbox').on('change', function() {
				airwallexConnectionFlow.updateCredentialFields();
				airwallexConnectionFlow.testConnection();
				airwallexConnectionFlow.initializeCheckboxState();
				airwallexConnectionFlow.toggleConnectionMethod();
			});

			$('.wc-airwallex-connect-api-key-button').on('click', function(e: JQuery.ClickEvent) {
				e.preventDefault();
				airwallexConnectionFlow.connectViaApiKey();
			});

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
			}).done(function (response: { success?: boolean; redirect_url?: string; message?: string }) {
				if (response.success) {
					// eslint-disable-next-line @typescript-eslint/no-explicit-any
					(window as any).onbeforeunload = '';
					$(window).off('beforeunload');
					location.href = response.redirect_url as string;
				} else {
					window.alert('Failed to connect account. ' + response.message);
				}
				// eslint-disable-next-line @typescript-eslint/no-explicit-any
			}).fail(function (error: any) {
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
			const env = ele.length ? (ele.is(':checked') ? 'sandbox' : 'prod') : '';
			$.ajax({
				type: 'POST',
				data: {
					security: awxAdminSettings.apiSettings.nonce.connectionTest,
					env,
				},
				url: awxAdminSettings.apiSettings.ajaxUrl.connectionTest,
			}).done(function (response: { success?: boolean }) {
				airwallexConnectionFlow.toggleConnected(!!response.success);
				airwallexConnectionFlow.displayAlert();
				airwallexConnectionFlow.displayConnectionFailedAlert();
				airwallexConnectionFlow.toggleLoadingSpinner(ele, false);
				airwallexConnectionFlow.toggleConnectedAccount();
				// eslint-disable-next-line @typescript-eslint/no-explicit-any
			}).fail(function (error: any) {
				console.log(error);
				airwallexConnectionFlow.displayAlert();
				airwallexConnectionFlow.toggleLoadingSpinner(ele, false);
				airwallexConnectionFlow.toggleConnectedAccount();
			});
		},

		toggleConnected: function(connected: boolean) {
			if (connected) {
				$('#awx-account-not-connected').hide();
				$('#awx-account-connected').show();
			} else {
				$('#awx-account-not-connected').show();
				$('#awx-account-connected').hide();
			}
		},

		toggleLoadingSpinner: function(ele: JQuery, showSpinner: boolean) {
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
				$('.wc-airwallex-account-name').text(awxAdminSettings.apiSettings.accountName.sandbox);
			}
			if ($('#awx-account-connected').is(':visible')) {
				$('.wc-airwallex-connect-button-label').text(awxAdminSettings.apiSettings.connectButtonText.manage);
			} else {
				$('.wc-airwallex-connect-button-label').text(awxAdminSettings.apiSettings.connectButtonText.connect);
			}
		},

		initializeCheckboxState: function() {
			const env = airwallexConnectionFlow.getEnv();
			const useApiKey = awxAdminSettings.apiSettings.useApiKey[env];
			$('.wc-airwallex-use-api-key-checkbox').prop('checked', useApiKey === 'yes');
		},

		toggleConnectionMethod: function() {
			const isChecked = $('.wc-airwallex-use-api-key-checkbox').is(':checked');
			if (isChecked) {
				airwallexConnectionFlow.showApiKeyFields();
			} else {
				airwallexConnectionFlow.showConnectButton();
			}
		},

		showApiKeyFields: function() {
			$('.wc-airwallex-connect-button').closest('tr').hide();
			$('#airwallex-online-payments-gatewayairwallex_general_client_id').closest('tr').show();
			$('#airwallex-online-payments-gatewayairwallex_general_api_key').closest('tr').show();
			$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('tr').show();
			$('#wc-airwallex-connect-api-key-button-row').show();
		},

		showConnectButton: function() {
			$('.wc-airwallex-connect-button').closest('tr').show();
			$('#airwallex-online-payments-gatewayairwallex_general_client_id').closest('tr').hide();
			$('#airwallex-online-payments-gatewayairwallex_general_api_key').closest('tr').hide();
			$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').closest('tr').hide();
			$('#wc-airwallex-connect-api-key-button-row').hide();
		},

		connectViaApiKey: function() {
			const $button = $('.wc-airwallex-connect-api-key-button');
			const $message = $('.wc-airwallex-connection-test-message');
			const i18n = awxAdminSettings.apiSettings.i18n.connectionTest;

			const $clientIdField = $('#airwallex-online-payments-gatewayairwallex_general_client_id');
			const $apiKeyField = $('#airwallex-online-payments-gatewayairwallex_general_api_key');
			const $webhookSecretField = $('#airwallex-online-payments-gatewayairwallex_general_webhook_secret');

			const clientId = $.trim($clientIdField.val() as string);
			const apiKey = $.trim($apiKeyField.val() as string);
			const webhookSecret = $.trim($webhookSecretField.val() as string);

			if (!clientId || !apiKey || !webhookSecret) {
				$message.removeClass('success error').addClass('error')
					.html('<span style="color: #dc3232;">' + i18n.requiredFields + '</span>')
					.show();
				return;
			}

			$message.hide();
			$button.prop('disabled', true);

			const env = airwallexConnectionFlow.getEnv();

			$.ajax({
				type: 'POST',
				data: {
					security: awxAdminSettings.apiSettings.nonce.connectionTest,
					env: env,
					client_id: clientId,
					api_key: apiKey,
					connect_with_api_key: 'true',
				},
				url: awxAdminSettings.apiSettings.ajaxUrl.connectionTest,
			}).done(function (response: { success?: boolean; message?: string }) {
				$button.prop('disabled', false);

				if (response.success) {
					window.onbeforeunload = null;
					$(window).off('beforeunload');

					const $form = $clientIdField.closest('form');
					if ($form.length) {
						if (!$form.find('input[name="save"]').length) {
							$form.append('<input type="hidden" name="save" value="Save changes">');
						}
						$form.trigger('submit');
					}
				} else {
					$message.removeClass('success').addClass('error')
						.html('<span style="color: #dc3232;">' + (response.message || i18n.failedMessage) + '</span>')
						.show();
				}
				// eslint-disable-next-line @typescript-eslint/no-explicit-any
			}).fail(function (error: any) {
				console.log(error);
				$button.prop('disabled', false);
				$message.removeClass('success').addClass('error')
					.html('<span style="color: #dc3232;">' + i18n.errorMessage + '</span>')
					.show();
			});
		},

		showProdConnectedAlert: function() {
			$('.wc-airwallex-connection-alert.wc-airwallex-account-connected').show();
		},

		hideProdConnectedAlert: function() {
			$('.wc-airwallex-connection-alert.wc-airwallex-account-connected').hide();
		},

		getEnv: function(): 'sandbox' | 'prod' {
			return $('#airwallex-online-payments-gatewayairwallex_general_enable_sandbox').is(':checked') ? 'sandbox' : 'prod';
		},

		updateCredentialFields: function() {
			const env = airwallexConnectionFlow.getEnv();
			const credentials = awxAdminSettings.apiSettings.credentials[env];
			const mask = awxAdminSettings.apiSettings.secretMask || '';

			if (credentials) {
				$('#airwallex-online-payments-gatewayairwallex_general_client_id').val(credentials.client_id || '');
				$('#airwallex-online-payments-gatewayairwallex_general_api_key').val(credentials.api_key_set ? mask : '');
				$('#airwallex-online-payments-gatewayairwallex_general_webhook_secret').val(credentials.webhook_secret_set ? mask : '');
			}
		}
	};

	airwallexConnectionFlow.init();

	const saveCardEnableSelector = '#airwallex-online-payments-gatewayairwallex_card_save_card_enabled';
	const skipCVCSelector = '#airwallex-online-payments-gatewayairwallex_card_skip_cvc_enabled';
	const toggleCVCField = function(): void {
		if ($(saveCardEnableSelector).prop('checked')) {
			$(skipCVCSelector).closest('tr').show();
		} else {
			$(skipCVCSelector).closest('tr').hide();
		}
	}
	toggleCVCField();
	$(saveCardEnableSelector).on('change', toggleCVCField);

	const formTypeSelector = '#airwallex-online-payments-gatewayairwallex_card_checkout_form_type';
	const toggleSaveCardField = function(): void {
		if ($(formTypeSelector).val() === 'inline') {
			$(saveCardEnableSelector).closest('tr').show();
			toggleCVCField();
		} else {
			$(saveCardEnableSelector).closest('tr').hide();
			$(skipCVCSelector).closest('tr').hide();
		}
	}
	toggleSaveCardField();
	$(formTypeSelector).on('change', toggleSaveCardField);

	const paymentPageTemplateSelector = '#airwallex-online-payments-gatewayairwallex_general_payment_page_template';
	if (awxAdminSettings?.apiSettings?.isForceSetPaymentFormAsWPPage) {
		$(paymentPageTemplateSelector).val('wordpress_page');
	}

	const initPaymentMethodCheck = (): void => {
		const awxEnableCheckboxSelector = '.is-awx-payment-method-enabled input';
		$(document).on("change", awxEnableCheckboxSelector, function () {
			if (!$(awxEnableCheckboxSelector).prop('checked')) return;
			$('.is-awx-payment-method-enabled .wc-awx-checkbox-spinner').css('display', 'inline-block');
			$(".is-awx-payment-method-enabled .wc-awx-checkbox-error-message").hide(300);
			isPaymentMethodEnabled($("[name='awx_payment_method_type']").val() as string)
				.then((response: { success?: boolean; is_enabled?: boolean }) => {
					if (!response.success || !response.is_enabled) {
						$(".is-awx-payment-method-enabled .awx-payment-method-not-enabled").show();
						$(awxEnableCheckboxSelector).prop("checked", false);
					}
				})
				.fail(() => {
					$(".is-awx-payment-method-enabled .awx-request-failed").show();
					$(awxEnableCheckboxSelector).prop("checked", false);
				})
				.always(() => {
					$('.is-awx-payment-method-enabled .wc-awx-checkbox-spinner').hide();
				});
		});
	};

	const isPaymentMethodEnabled = (paymentMethodType: string) => {
		return $.ajax({
			type: "GET",
			url: awxAdminSettings.paymentMethodStatus.url,
			data: {
				security: awxAdminSettings.paymentMethodStatus.nonce,
				payment_method_type: paymentMethodType,
			},
		});
	};

	initPaymentMethodCheck();

	const initPOSTerminalBind = (): void => {

		const container = $(".awx-pos-device-container");
		if (!container.length) return;

		const inputEl  = container.find(".awx-pos-device-input");
		const listEl   = container.find(".awx-pos-device-list");
		const noDataEl = container.find(".awx-pos-device-no-data");
		const infoEl   = container.find(".awx-pos-device-info");

		const btnPrevEl  = container.find(".awx-pos-prev-btn");
		const btnNextEl  = container.find(".awx-pos-next-btn");

		const terminalListItemEl = container.find(".awx-pos-item-template .awx-pos-item");

		const pages = { before: "", after: "" };

		interface Terminal { id?: string; nick_name?: string; serial_number?: string }

		const updateBoundInfo = (terminal: Terminal | undefined): void => {
			if (!terminal || !terminal.id) {
				infoEl.hide();
				return;
			}
			infoEl.show();
			infoEl.find(".awx-pos-info-id .value").text(terminal.id || "");
			infoEl.find(".awx-pos-info-nickname .value").text(terminal.nick_name || "");
			infoEl.find(".awx-pos-info-serial .value").text(terminal.serial_number || "");
		};

		const renderList = (terminals: Terminal[]): void => {
			listEl.empty();

			if (!terminals.length) {
				noDataEl.show();
				return;
			}

			noDataEl.hide();

			terminals.forEach(terminal => {
				const row = terminalListItemEl.clone(true);
				row.attr("data-id", terminal.id as string);
				row.find(".awx-pos-template-nickname .value").text(terminal.nick_name as string);
				row.find(".awx-pos-template-serial .value").text(terminal.serial_number as string);

				row.on("click", () => {
					inputEl.val(terminal.id as string);
					listEl.find(".awx-pos-item").removeClass("active");
					row.addClass("active");
					$('button[name="save"]').removeAttr('disabled');
				});

				listEl.append(row);
			});
		};

		const loadTerminals = (page: string): void => {
			$.ajax({
				url: awxAdminPOSSettings.ajaxUrl.getPOSTerminals,
				method: "GET",
				data: {
					security: awxAdminPOSSettings.nonce.getPOSTerminals,
					page: page,
				},
				beforeSend: () => {
					$(".awx-pos-device-list").css("opacity", "0.5");
				},
				success: (res: POSTerminalsResponse) => {
					if (!res.success) return;
					$(".awx-pos-device-list").css("opacity", "1");
					updateBoundInfo(awxAdminPOSSettings.boundTerminal);

					renderList(res.data.data);

					pages.before = res.data.page_before ?? "";
					pages.after  = res.data.page_after ?? "";
					$(".awx-pos-pagination").toggle(!!(pages.before || pages.after));

					btnPrevEl.prop("disabled", !pages.before);
					btnNextEl.prop("disabled", !pages.after);

					const selectedId = inputEl.val();
					if (selectedId) {
						listEl.find(`[data-id='${selectedId}']`).addClass("active");
					}
				}
			});
		};

		btnPrevEl.on("click", () => loadTerminals(pages.before));
		btnNextEl.on("click", () => loadTerminals(pages.after));

		loadTerminals("");
	};

	initPOSTerminalBind();

	const apmLogoTemplateEl = $(".awx-apm-logo-template");
	const apmNameTemplateEl = $(".awx-apm-name-template");
	if (apmLogoTemplateEl.length) {
		$.ajax({
		url: `${awxAdminApmSettings.ajaxUrl.getApmData}&security=${awxAdminApmSettings.nonce.getApmData}`,
		method: 'GET',
		dataType: 'json',
		success(response: ApmDataResponse) {
			// `ApmDataResponse.data` and its inner fields are declared
			// optional but PHP always emits them for this endpoint;
			// preserve the original non-null access pattern via local
			// narrows so the loop bodies stay assertion-free.
			const apmData = response.data as NonNullable<ApmDataResponse['data']>;
			const logos = apmData.all_logos as Record<string, string>;
			const activeLogos = apmData.active_logos as Record<string, string>;
			const names = apmData.all_names as Record<string, string>;
			const activeNames = apmData.active_names as string[];
			const activeTip = apmData.active_tip as string;

			Object.keys(logos).forEach(name => {
				const row = apmLogoTemplateEl.clone(true);
				row.find(".awx-apm-logo-item .awx-apm-logo").attr('src', logos[name]);
				row.find(".awx-apm-logo-item .awx-apm-logo-checkbox").attr('value', name);
				if (activeLogos[name]) {
					row.find(".awx-apm-logo-item .awx-apm-logo-checkbox").attr('checked', 'checked');
				}
				$(".awx-apm-logos").append(row.html());
			});

			Object.keys(names).forEach(name => {
				const row = apmNameTemplateEl.clone(true);
				row.find(".awx-apm-name-item input").attr('value', name);
				row.find(".awx-apm-name-item .awx-display-name").replaceWith(names[name]);
				if (activeNames.includes(name)) {
					row.find(".awx-apm-name-item input").attr('checked', 'checked');
				}
				if (['applepay', 'googlepay'].includes(name)) {
					row.find(".awx-apm-name-item").append(`<span class="woocommerce-help-tip" aria-label="${activeTip}"></span>`);
				}
				$(".awx-apm-names").append(row.html());
			});
		}});
	}
});
