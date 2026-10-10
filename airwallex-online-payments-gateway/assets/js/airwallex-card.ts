import type { Payment } from '@airwallex/components-sdk';

import { initAirwallex } from "./utils";
import { getCardData } from "./api";

interface AirwallexCardToken {
    id: string;
    type: string;
    formatted_type: string;
    last4: string;
    expiry_month: string;
    expiry_year: string;
    is_hide_cvc_element?: boolean;
}

interface CardCheckoutResult {
    result?: 'success' | 'failure' | string;
    redirect?: string;
    messages?: string;
    paymentMethodId?: string;
    paymentIntent?: string;
    customerId?: string;
    clientSecret?: string;
    currency?: string;
    createConsent?: boolean;
    orderId?: string | number;
    orderKey?: string;
    tokenId?: string | number;
    error?: string | { message?: string };
}

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

    const getPostData = function ( data: string ): string {
        const tokenId = $('input[name="save-card"]:checked').attr('id');
        if (tokenId) {
            data += data ? '&' : '';
            data += 'token=' + tokenId;
        }
        return data;
    }

    const getConfirmationUrl = function (confirmationUrl: string, orderId: string | number, paymentIntent: string, orderKey?: string): string {
        const finalConfirmationUrl = new URL(confirmationUrl);
        const params = new URLSearchParams(finalConfirmationUrl.search);
        params.set('order_id', String(orderId));
        params.set('intent_id', paymentIntent);
        if (orderKey) {
            params.set('key', orderKey);
        }
        const airwallexSave = document.getElementById('airwallex-save') as HTMLInputElement | null;
        if (airwallexSave && airwallexSave.checked) {
            params.set('is_airwallex_save_checked', 'true');
        }
        finalConfirmationUrl.search = params.toString();
        return finalConfirmationUrl.toString();
    }

    let tokens: Record<string, AirwallexCardToken> = {};
    // SDK Element instances, typed via `@airwallex/components-sdk`'s
    // `Payment.*ElementType` (type-only import; the runtime comes from
    // the CDN-loaded `elements.bundle.min.js`). Pattern C: keep methods
    // inline; never detach into a `const m = el.method` — see
    // `.cursor/rules/javascript-this-binding.mdc`.
    //
    // The CVC element's `mount` accepts a second-arg options object at
    // runtime (the CDN ignores it on this version); the published
    // `@airwallex/components-sdk` types only declare the 1-arg signature.
    // Widen the slot via intersection so the verbatim 2-arg call below
    // stays intact without introducing a runtime alias.
    type CvcElement = Payment.CvcElementType & {
        mount(domElement: string, options?: { autoCapture?: boolean }): null | HTMLElement;
    };
    let cvcElement: CvcElement | undefined;
    let airwallexSlimCard: Payment.CardElementType | undefined;
    let isCVCCompleted = true;

    const initCardElement = (): void => {
        const allowedCardNetworks: Payment.CardNetwork[] = ['discover', 'visa', 'mastercard', 'maestro', 'unionpay', 'amex', 'jcb', 'diners'];
        // `Payment.InputStyle.base` is declared as `PseudoClassStyle &
        // CSSProperties`; TS rejects the nested `'::placeholder'` object
        // because the `CSSProperties` index signature requires
        // `string | number | undefined` and refuses an object value.
        // Cast through `unknown` so the precise `createElement('card',
        // ...)` overload still resolves to `Payment.CardElementType`
        // while keeping the literal verbatim and dropping the `any`
        // eslint-disable.
        const elementStyle = {
            base: {
                fontSize: '14px',
                "::placeholder": {
                    'color': 'rgba(135, 142, 153, 1)'
                },
            }
        } as unknown as Payment.InputStyle;
        airwallexSlimCard = Airwallex!.createElement('card', {
            autoCapture: autoCapture,
            allowedCardNetworks: allowedCardNetworks,
            style: elementStyle,
        })!;

        airwallexSlimCard.mount('airwallex-card');
        setInterval(function () {
            if (document.getElementById('airwallex-card') && !document.querySelector('#airwallex-card iframe')) {
                try {
                    airwallexSlimCard!.mount('airwallex-card');
                } catch(e) {
                    console.warn(e);
                }
            }
        }, 1000);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        window.addEventListener('onError', (event: any) => {
            if (!event.detail) {
                return;
            }
            const { error } = event.detail;
            AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', error.message || ''));
        });

        showTokens();
    };

    const is_valid_json = ( raw_json: string ): boolean => {
        try {
            const json = JSON.parse( raw_json );

            return json && 'object' === typeof json;
        } catch ( e ) {
            return false;
        }
    };

    const detachUnloadEventsOnSubmit = (e?: Event): boolean | undefined => {
        if((navigator.userAgent.indexOf('MSIE') !== -1 ) || (!!document.documentMode)) {
            e?.preventDefault();
            return undefined;
        }

        return true;
    };

    $('form.checkout').on('checkout_place_order_airwallex_card',  function (this: HTMLFormElement) {
        const $form = $(this);

        $form.addClass( 'processing' );

        $.ajaxSetup( {
            dataFilter: function( raw_response: string, dataType: string ) {
                if ( 'json' !== dataType ) {
                    return raw_response;
                }

                if ( is_valid_json( raw_response ) ) {
                    return raw_response;
                } else {
                    const maybe_valid_json = raw_response.match( /{"result.*}/ );

                    if ( null === maybe_valid_json ) {
                        // intentional empty branch matches original JS
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
        }).done(function ( result: CardCheckoutResult ) {
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

        }).fail( function( error: JQuery.jqXHR ) {
            $(awxCheckoutForm).unblock();
            detachUnloadEventsOnSubmit();
            AirwallexClient.displayCheckoutError(awxCheckoutForm, error.responseText as string);
        });
        return false;
    });

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const createConsent = async (next_triggered_by: string): Promise<any> => {
        const response = await $.ajax({
            type: 'GET',
            url: awxEmbeddedCardData.getCustomerClientSecretAjaxUrl,
            data: {
                security: awxEmbeddedCardData.getCustomerClientSecretNonce,
            },
        });
        return await Airwallex!.createPaymentConsent({
            customer_id: response.customer_id,
            client_secret: response.client_secret,
            element: airwallexSlimCard,
            next_triggered_by: next_triggered_by,
            currency: awxEmbeddedCardData.currency,
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
        } as any)
    };

    $(document.body).on('click', '#place_order', async function (event: JQuery.Event) {
        if ($('input[name="payment_method"]:checked').val() !== 'airwallex_card') {
            return;
        }

        if (awxEmbeddedCardData.isAccountPage) {
            event.preventDefault();
            airwallexCheckoutBlock('#payment');

            const alert = $(".awx-alert");
            alert.hide();
            try {
                await createConsent('customer');
            } catch (err) {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const e = err as any;
                let msg = e?.message;
                if (e?.code === 'resource_already_exists') {
                    msg = awxEmbeddedCardData.resourceAlreadyExistsMessage;
                }
                $(".awx-alert .body").html(msg);
                alert.removeClass('awx-hidden').show();
                $("#payment").unblock();
                return;
            }
            $("#add_payment_method").trigger('submit');
            $("#payment").unblock();
            return;
        }

        if (awxCommonData.isOrderPayPage) {
            event.preventDefault();
            if (window.location.search.includes('change_payment_method')) {
                event.preventDefault();
                const alert = $(".awx-alert");
                alert.hide();
                $('#place_order').prop('disabled', true);
                try {
                    const result = await createConsent('merchant');
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
                    const e = err as { message?: string };
                    const msg = e?.message;
                    $(".awx-alert .body").html(msg as string);
                    alert.removeClass('awx-hidden').show();
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
            }).done((response: CardCheckoutResult) => {
                if (response.result === 'success') {
                    confirmSlimCardPayment(response, airwallexSlimCard);
                } else {
                    $('#order_review').unblock();
                    AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', (response.error as string) || ''));
                }
            }).fail((error: JQuery.jqXHR) => {
                $('#order_review').unblock();
                AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', error.statusText || ''));
            });
        }
    });

    const airwallexCheckoutBlock = (element: string | HTMLElement | JQuery): void => {
        //timeout necessary because of event order in plugin CheckoutWC
        setTimeout(function () {
            $(element as JQuery).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        }, 50);
    }

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const confirmSlimCardPayment = async (result: CardCheckoutResult, element: any): Promise<void> => {
        if (!result || result.error) {
            AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', ''));
            $(awxCheckoutForm).unblock();
            return;
        }

        if ( result.redirect ) {
            if ( -1 === result.redirect.indexOf( 'https://' ) || -1 === result.redirect.indexOf( 'http://' ) ) {
                window.location.href = result.redirect;
            } else {
                window.location.href = decodeURI( result.redirect );
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
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const confirmData: any = {
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
                await cvcElement!.confirm(confirmData);
            } else if (result.createConsent) {
                await Airwallex!.confirmPaymentIntent({
                    intent_id: result.paymentIntent!,
                    customer_id: result.customerId,
                    client_secret: result.clientSecret!,
                    currency: result.currency,
                    element: element,
                    payment_consent: {
                        merchant_trigger_reason: 'scheduled',
                        next_triggered_by: 'merchant'
                    },
                    billing: AirwallexClient.getBillingInformation(),
                    // eslint-disable-next-line @typescript-eslint/no-explicit-any
                } as any)
            } else if ($('#airwallex-save').prop('checked')) {
                await Airwallex!.createPaymentConsent({
                    intent_id: result.paymentIntent,
                    customer_id: result.customerId,
                    client_secret: result.clientSecret!,
                    currency: result.currency,
                    element: element,
                    next_triggered_by: 'customer',
                    billing: AirwallexClient.getBillingInformation(),
                    // eslint-disable-next-line @typescript-eslint/no-explicit-any
                } as any);
            } else {
                await Airwallex!.confirmPaymentIntent({
                    element: element,
                    intent_id: result.paymentIntent!,
                    client_secret: result.clientSecret!,
                    payment_method: {
                        card: {
                            name: AirwallexClient.getCardHolderName()
                        },
                        billing: AirwallexClient.getBillingInformation()
                    },
                    // eslint-disable-next-line @typescript-eslint/no-explicit-any
                } as any);
            }
        } catch (err) {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const e = err as any;
            if (e?.code !== 'invalid_status_for_operation') {
                $.ajax({
                    url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + result.orderId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response: { success: boolean; message?: string }) {
                        const errMessage = response.success ? (e?.message || '') : response.message;
                        AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', errMessage as string));
                        $(awxCheckoutForm).unblock();
                    },
                    error: function(xhr: JQuery.jqXHR) {
                        // eslint-disable-next-line @typescript-eslint/no-explicit-any
                        let errMessage: string = (xhr as any).responseText;
                        // eslint-disable-next-line @typescript-eslint/no-explicit-any
                        const responseJSON = (xhr as any).responseJSON;
                        if (responseJSON && responseJSON.message) {
                            errMessage = responseJSON.message;
                        }
                        AirwallexClient.displayCheckoutError(awxCheckoutForm, String(errorMessage).replace('%s', errMessage));
                        $(awxCheckoutForm).unblock();
                    }
                });

                return;
            }
        }

        let targetConfirmationUrl = getConfirmationUrl(confirmationUrl, result.orderId!, result.paymentIntent!, result.orderKey);
        if (result.tokenId) {
            targetConfirmationUrl += "&token_id=" + parseInt(String(result.tokenId), 10);
        }
        location.href = targetConfirmationUrl;
    }

    if (awxCommonData) {
        initAirwallex(env, locale, initCardElement);
    }

    const resetSaveCardUI = function (): void {
        $(".airwallex-container .new-card, .save-card input").removeAttr("checked");
        $(".airwallex-container .save-card input").removeAttr("checked");
        $(".airwallex-container .awx-new-card-title, #airwallex-card, .line.save, .cvc-title, .cvc-container").hide();
        $('.airwallex-container .save-card label').css('font-weight', 400);
    };

    $(document).on('change', 'input[name="save-card"]', function(this: HTMLInputElement) {
        resetSaveCardUI();
        $(this).prop('checked', true);
        $("#airwallex-new-card").removeAttr("checked");
        $(".cvc-title, .cvc-container").hide();

        $('.airwallex-container label').css('font-weight', 400);

        const tokenId = $('input[name="save-card"]:checked').attr('id') as string;
        $('label[for="' + tokenId + '"]').css('font-weight', 700);
        const cvcContainerElement = $(".save-card-" + tokenId + " .cvc-title, .save-card-" + tokenId + " .cvc-container");
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
            Airwallex!.destroyElement('cvc');
        }
        // See the same-shape `elementStyle` in `initCardElement` for the
        // reason this is cast through `unknown`.
        const cvcElementStyle = {
            base: {
                fontSize: '14px',
                "::placeholder": {
                    'color': 'rgba(135, 142, 153, 1)'
                },
            }
        } as unknown as Payment.InputStyle;
        cvcElement =  Airwallex!.createElement('cvc', {
            style: cvcElementStyle,
            placeholder: awxEmbeddedCardData.CVC,
            cvcLength
        }) as CvcElement;
        cvcElement.mount(tokenId + '-cvc', { autoCapture });
        isCVCCompleted = true;
        if (!tokens?.[tokenId]?.is_hide_cvc_element) {
            isCVCCompleted = false;
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            cvcElement.on('change', (event: any) => {
                isCVCCompleted = event.detail.complete;
            })
        }
    });

    $(document).on('change', 'input[name="new-card"]', function() {
        resetSaveCardUI();
        $(".awx-new-card-title, #airwallex-card, .line.save").show();
        $('label[for="airwallex-new-card"]').css('font-weight', 700);
    });

    let cachedCardLogos: Record<string, string> | null = null;

    const showTokens = async (): Promise<void> => {
        if ( ! $('#payment_method_airwallex_card').is(":checked") ) {
            return;
        }

        if ( ! $(".airwallex-container .save-cards").length ) {
            return;
        }

        if (!tokens || !Object.keys(tokens).length) {
            airwallexCheckoutBlock('#payment');
            const res = await $.ajax({
                type: 'GET',
                url: awxEmbeddedCardData.getTokensAjaxUrl,
                data: {
                    security: awxEmbeddedCardData.getTokensNonce,
                },
            });
            $("#payment").unblock();
            tokens = res.tokens;
        }

        $(".payment_method_airwallex_card .wc-awx-checkbox-spinner").hide();
        if (!tokens || !Object.keys(tokens).length) {
            $(".airwallex-container").removeClass('awx-hidden').show();
            $(".airwallex-container .new-card").hide();
            return;
        }
        let tokensHtml = '';
        for (const token of Object.values(tokens)) {
            let logoIndex = token.type.toLowerCase().replace(/\s+/g, "");
            if (logoIndex === 'americanexpress') logoIndex = 'amex';
            if (logoIndex === 'dinersclub') logoIndex = 'diners';
            tokensHtml += `       
                <div class="save-card save-card-${token.id}">
                    <div class="save-card-information line">
                        <input type="radio" name="save-card" id="${token.id}"> 
                        <label for="${token.id}">
                            <span>${token.formatted_type} •••• ${token.last4} (expires ${token.expiry_month}/${token.expiry_year.slice(-2)})</span> 
                            <img style="display: none;" data-type="${'card_' + logoIndex}" class="airwallex-card-icon" alt="Credit Card">
                        </label> 
                    </div>
                    <div class="cvc-title" style="display: none;">Security code</div>   
                    <div id="${token.id}-cvc" class="cvc-container" style="display: none;"></div>    
                </div>
            `;
        }
        $(".airwallex-container").removeClass('awx-hidden').show();
        $(".airwallex-container .save-cards").html(tokensHtml);
        $(".airwallex-container .new-card").removeClass('awx-hidden').show();
        $('input[name="save-card"]').first().trigger('click');

        const renderLogos = (logos: Record<string, string>): void => {
            $('.airwallex-card-icon').each(function (this: HTMLElement) {
                const src = logos[$(this).data('type')];

                if (src) {
                    $(this).attr('src', src);
                    $(this).css('display', 'block');
                }
            });
        };

        if (cachedCardLogos) {
            renderLogos(cachedCardLogos);
            return;
        }

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        getCardData().then((data: any) => {
            cachedCardLogos = data?.data?.logos;
            renderLogos(cachedCardLogos ?? {});
        });
    }
    $(document).on('change', '#payment_method_airwallex_card', showTokens);
    $(document).on('updated_checkout', showTokens);

    const showCardLogos = async (): Promise<void> => {
        const $cardLogoElement = $('#awx-card-logos-classic');
        if (!$cardLogoElement.length) return;

        const renderLogos = (logos: Record<string, string> | null | undefined): void => {
            const keys = Object.keys(logos || {});
            if (!keys.length) return;

            const logosHtml = keys
                .map(key => `<img src="${logos![key]}" class="airwallex-card-icon" title="${key}">`)
                .join('');

            $cardLogoElement.replaceWith(logosHtml);
        };

        if (cachedCardLogos) {
            renderLogos(cachedCardLogos);
            return;
        }

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        getCardData().then((data: any) => {
            cachedCardLogos = data?.data?.logos;
            renderLogos(cachedCardLogos);
        });
    };

    $(document).on('updated_checkout', showCardLogos);

    // `incompleteMessage` is destructured for parity with the original
    // file (kept reachable for future card-incomplete handling) but
    // currently unused; reference it once so TS doesn't flag the
    // destructure as dead.
    void incompleteMessage;
});
