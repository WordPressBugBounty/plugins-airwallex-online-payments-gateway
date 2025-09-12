import { initAirwallex } from "./utils";

/** global awxCommonData, awxEmbeddedCardData */
jQuery(function ($) {
    const {
        env,
        locale,
        confirmationUrl,
        isOrderPayPage,
    } = awxCommonData;
    const awxCheckoutForm = isOrderPayPage ? '#order_review' : 'form.checkout';
    const {
        autoCapture,
        errorMessage,
        incompleteMessage,
    } = awxEmbeddedCardData;

    const getPostData = function ( data ) {
        let tokenId = $('input[name="save-card"]:checked').attr('id');
        if (tokenId) {
            data += data ? '&' : '';
            data += 'token=' + tokenId;
        }
        return data;
    }

    const getConfirmationUrl = function (confirmationUrl, orderId, paymentIntent) {
        const finalConfirmationUrl = new URL(confirmationUrl);
        const params = new URLSearchParams(finalConfirmationUrl.search);
        params.set('order_id', orderId);
        params.set('intent_id', paymentIntent);
        const airwallexSave = document.getElementById('airwallex-save');
        if (airwallexSave && airwallexSave.checked) {
            params.set('is_airwallex_save_checked', 'true');
        }
        finalConfirmationUrl.search = params.toString();
        return finalConfirmationUrl.toString();
    }

    let tokens = [];
    let cvcElement, airwallexSlimCard;
    let isCVCCompleted = true;

    const initCardElement = () => {
        airwallexSlimCard = Airwallex.createElement('card', {
            autoCapture: autoCapture,
            allowedCardNetworks: ['discover', 'visa', 'mastercard', 'maestro', 'unionpay', 'amex', 'jcb', 'diners'],
            style: {
                base: {
                    fontSize: '14px',
                    "::placeholder": {
                        'color': 'rgba(135, 142, 153, 1)'
                    },
                }
            }
        });

        let domElement = airwallexSlimCard.mount('airwallex-card');
        setInterval(function () {
            if (document.getElementById('airwallex-card') && !document.querySelector('#airwallex-card iframe')) {
                try {
                    domElement = airwallexSlimCard.mount('airwallex-card');
                } catch(e) {
                    console.warn(e);
                }
            }
        }, 1000);

        window.addEventListener('onError', (event) => {
            if (!event.detail) {
                return;
            }
            const { error } = event.detail;
            AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', error.message || ''));
        });

        showTokens();
    };

    const is_valid_json = ( raw_json ) => {
        try {
            var json = JSON.parse( raw_json );

            return json && 'object' === typeof json;
        } catch ( e ) {
            return false;
        }
    };

    const detachUnloadEventsOnSubmit = (e) => {
        if((navigator.userAgent.indexOf('MSIE') !== -1 ) || (!!document.documentMode)) {
            e.preventDefault();
            return undefined;
        }

        return true;
    };

    $('form.checkout').on('checkout_place_order_airwallex_card',  function () {
        let $form = $(this);

        $form.addClass( 'processing' );

        $.ajaxSetup( {
            dataFilter: function( raw_response, dataType ) {
                if ( 'json' !== dataType ) {
                    return raw_response;
                }

                if ( is_valid_json( raw_response ) ) {
                    return raw_response;
                } else {
                    var maybe_valid_json = raw_response.match( /{"result.*}/ );

                    if ( null === maybe_valid_json ) {
                    } else if ( is_valid_json( maybe_valid_json[0] ) ) {
                        raw_response = maybe_valid_json[0];
                    }
                }

                return raw_response;
            }
        } );

        airwallexCheckoutBlock(awxCheckoutForm);

        $.ajax({
            type:		'POST',
            url:		awxEmbeddedCardData.getCheckoutAjaxUrl,
            data:		getPostData( $form.serialize() ),
            dataType:   'json',
        }).done(function ( result ) {
            detachUnloadEventsOnSubmit();

            $( '.checkout-inline-error-message' ).remove();

            if ('success' !== result.result) {
                let message = 'Error processing checkout. Please try again.';
                if (result.messages && typeof result.messages === 'string') {
                    message = result.messages;
                }
                AirwallexClient.displayCheckoutError(awxCheckoutForm, message);
                return;
            }

            confirmSlimCardPayment(result, airwallexSlimCard);

        }).fail( function( error ) {
            $(awxCheckoutForm).unblock();
            detachUnloadEventsOnSubmit();
            AirwallexClient.displayCheckoutError(awxCheckoutForm, error.responseText);
        });
        return false;
    });

    const createConsent = async (next_triggered_by) => {
        let response = await $.ajax({
            type: 'GET',
            url: awxEmbeddedCardData.getCustomerClientSecretAjaxUrl,
        });
        return await Airwallex.createPaymentConsent({
            customer_id: response.customer_id,
            client_secret: response.client_secret,
            element: airwallexSlimCard,
            next_triggered_by: next_triggered_by,
            currency: awxEmbeddedCardData.currency
        })
    };

    $(document.body).on('click', '#place_order', async function (event) {
        if ($('input[name="payment_method"]:checked').val() !== 'airwallex_card') {
            return;
        }

        if (awxEmbeddedCardData.isAccountPage) {
            event.preventDefault(); 
            airwallexCheckoutBlock('#payment');

            let alert = $(".awx-alert");
            alert.hide();
            try {
                await createConsent('customer');
            } catch (err) {
                let msg = err.message;
                if (err.code === 'resource_already_exists') {
                    msg = awxEmbeddedCardData.resourceAlreadyExistsMessage;
                }
                $(".awx-alert .body").html(msg);
                alert.show();
                $("#payment").unblock();
                return;
            }
            $("#add_payment_method").submit();
            $("#payment").unblock();
            return;
        }

        if (awxCommonData.isOrderPayPage) {
            event.preventDefault(); 
            if (window.location.search.includes('change_payment_method')) {
                event.preventDefault();
                let alert = $(".awx-alert");
                alert.hide();
                $('#place_order').prop('disabled', true);
                try {
                    let result = await createConsent('merchant');
                    const $form = $('#order_review');
                    $form.find('input[name="is_change_payment_method"], input[name="awx_customer_id"], input[name="awx_consent_id"]').remove();
                    const hiddenFields = [
                        { name: 'is_change_payment_method', value: 'true' },
                        { name: 'awx_customer_id', value: result.customer_id },
                        { name: 'awx_consent_id', value: result.payment_consent_id }
                    ];
                    hiddenFields.forEach(field => {
                        $('<input>', {
                            type: 'hidden',
                            name: field.name,
                            value: field.value
                        }).appendTo($form);
                    });
                    $form.trigger('submit');
                } catch (err) {
                    let msg = err.message;
                    $(".awx-alert .body").html(msg);
                    alert.show();
                    $('#place_order').prop('disabled', false);
                    return;
                }
                return;
            }

            airwallexCheckoutBlock(awxCheckoutForm);
            $.ajax({
                type: 'POST',
                data: getPostData( $("#order_review").serialize() ),
                url: awxCommonData.processOrderPayUrl,
            }).done((response) => {
                if (response.result === 'success') {
                    confirmSlimCardPayment(response, airwallexSlimCard);
                } else {
                    $('#order_review').unblock();
                    AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', response.error || ''));
                }
            }).fail((error) => {
                $('#order_review').unblock();
                AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', error.message || ''));
            });
        }
    });

    const airwallexCheckoutBlock = (element) => {
        //timeout necessary because of event order in plugin CheckoutWC
        setTimeout(function () {
            $(element).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        }, 50);
    }

    const confirmSlimCardPayment = async (result, element) => {
        if (!result || result.error) {
            AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', ''));
            $(awxCheckoutForm).unblock();
            return;
        }

        if ( result.redirect ) {
            if ( -1 === result.redirect.indexOf( 'https://' ) || -1 === result.redirect.indexOf( 'http://' ) ) {
                window.location = result.redirect;
            } else {
                window.location = decodeURI( result.redirect );
            }
            return;
        }

        try {
            if (result.paymentMethodId) {
                if (! isCVCCompleted && !awxEmbeddedCardData.isSkipCVCEnabled) {
                    AirwallexClient.displayCheckoutError(awxCheckoutForm, awxEmbeddedCardData.CVCIsNotCompletedMessage);
                    $(awxCheckoutForm).unblock();
                    return;
                }
                let confirmData = {
                    client_secret: result.clientSecret,
                    billing: AirwallexClient.getBillingInformation(),
                    customer_id: result.customerId,
                    intent_id: result.paymentIntent,
                    payment_method_id: result.paymentMethodId,
                    payment_method_options: {
                        card: {
                            auto_capture: autoCapture
                        }
                    },
                }
                if (result.createConsent) {
                    confirmData.currency = result.currency;
                    confirmData.payment_consent = {
                        merchant_trigger_reason: 'scheduled',
                        next_triggered_by: 'merchant'
                    };
                }
                await cvcElement.confirm(confirmData);
            } else if (result.createConsent) {
                await Airwallex.createPaymentConsent({
                    intent_id: result.paymentIntent,
                    customer_id: result.customerId,
                    client_secret: result.clientSecret,
                    currency: result.currency,
                    element: element,
                    next_triggered_by: 'merchant',
                    billing: AirwallexClient.getBillingInformation(),
                })
            } else if ($('#airwallex-save').prop('checked')) {
                await Airwallex.createPaymentConsent({
                    intent_id: result.paymentIntent,
                    customer_id: result.customerId,
                    client_secret: result.clientSecret,
                    currency: result.currency,
                    element: element,
                    next_triggered_by: 'customer',
                    billing: AirwallexClient.getBillingInformation(),
                });
            } else {
                await Airwallex.confirmPaymentIntent({
                    element: element,
                    intent_id: result.paymentIntent,
                    client_secret: result.clientSecret,
                    payment_method: {
                        card: {
                            name: AirwallexClient.getCardHolderName()
                        },
                        billing: AirwallexClient.getBillingInformation()
                    },
                });
            }
        } catch (err) {
            if (err.code !== 'invalid_status_for_operation') {
                AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', err.message || ''));
                $(awxCheckoutForm).unblock();
                return;
            }
        }

        let targetConfirmationUrl = getConfirmationUrl(confirmationUrl, result.orderId, result.paymentIntent);
        if (result.tokenId) {
            targetConfirmationUrl += "&token_id=" + parseInt(result.tokenId);
        }
        location.href = targetConfirmationUrl;
    }

    if (awxCommonData) {
        initAirwallex(env, locale, initCardElement);
    }

    const resetSaveCardUI = function () {
        $(".airwallex-container .new-card, .save-card input").removeAttr("checked");
        $(".airwallex-container .save-card input").removeAttr("checked");
        $(".airwallex-container .new-card-title, #airwallex-card, .line.save, .cvc-title, .cvc-container").hide();
        $('.airwallex-container .save-card label').css('font-weight', 400);
    };

    $(document).on('change', 'input[name="save-card"]', function() {
        resetSaveCardUI();
        $(this).prop('checked', true);
        $("#airwallex-new-card").removeAttr("checked");
        $(".cvc-title, .cvc-container").hide();

        $('.airwallex-container label').css('font-weight', 400);

        let tokenId = $('input[name="save-card"]:checked').attr('id');
        $('label[for="' + tokenId + '"]').css('font-weight', 700);
        let cvcContainerElement = $(".save-card-" + tokenId + " .cvc-title, .save-card-" + tokenId + " .cvc-container");
        if (tokens?.[tokenId]?.is_hide_cvc_element) {
            cvcContainerElement.hide();
        } else {
            cvcContainerElement.show();
        }
        let cvcLength = 3;
        if (['amex', 'american express'].includes(tokens?.[tokenId]?.type?.toLowerCase())) {
            cvcLength = 4;
        }
        if (cvcElement) {
            Airwallex.destroyElement('cvc');
        }
        cvcElement =  Airwallex.createElement('cvc', {
            style: {
                base: {
                    fontSize: '14px',
                    "::placeholder": {
                        'color': 'rgba(135, 142, 153, 1)'
                    },
                }
            },
            placeholder: awxEmbeddedCardData.CVC,
            cvcLength
        });
        cvcElement.mount(tokenId + '-cvc', { autoCapture });
        isCVCCompleted = true;
        if (!tokens?.[tokenId]?.is_hide_cvc_element) {
            isCVCCompleted = false;
            cvcElement.on('change', (event) => {
                isCVCCompleted = event.detail.complete;
            })
        }
    });

    $(document).on('change', 'input[name="new-card"]', function() {
        resetSaveCardUI();
        $(".new-card-title, #airwallex-card, .line.save").show();
        $('label[for="airwallex-new-card"]').css('font-weight', 700);
    });

    let showTokens = async () => {
        if ( ! $('#payment_method_airwallex_card').is(":checked") ) {
            return;
        }

        if ( ! $(".airwallex-container .save-cards").length ) {
            return;
        }

        if (!tokens || !Object.keys(tokens).length) {
            airwallexCheckoutBlock('#payment');
            let res = await $.ajax({
                type: 'GET',
                url: awxEmbeddedCardData.getTokensAjaxUrl,
            });
            $("#payment").unblock();
            tokens = res.tokens;
        }

        $(".payment_method_airwallex_card .wc-awx-checkbox-spinner").hide();
        if (!tokens || !Object.keys(tokens).length) {
            $(".airwallex-container").show();
            $(".airwallex-container .new-card").hide();
            return;
        }
        let tokensHtml = '';
        for (let token of Object.values(tokens)) {
            let logoIndex = token.type.toLowerCase().replace(/\s+/g, "");
            if (logoIndex === 'americanexpress') logoIndex = 'amex';
            if (logoIndex === 'dinersclub') logoIndex = 'diners';
            tokensHtml += `       
                <div class="save-card save-card-${token.id}">
                    <div class="save-card-information line">
                        <input type="radio" name="save-card" id="${token.id}"> 
                        <label for="${token.id}">
                            <span>${token.formatted_type} •••• ${token.last4} (expires ${token.expiry_month}/${token.expiry_year.slice(-2)})</span> 
                            <img src="${awxEmbeddedCardData.cardLogos['card_' + logoIndex]}" class="airwallex-card-icon" alt="Credit Card">
                        </label> 
                    </div>
                    <div class="cvc-title" style="display: none;">Security code</div>   
                    <div id="${token.id}-cvc" class="cvc-container" style="display: none;"></div>    
                </div>
            `;
        }
        $(".airwallex-container").show();
        $(".airwallex-container .save-cards").html(tokensHtml);
        $(".airwallex-container .new-card").show();
        $('input[name="save-card"]').first().trigger('click');
    }
    $(document).on('change', '#payment_method_airwallex_card', showTokens);
    $(document).on('updated_checkout', showTokens);
});
