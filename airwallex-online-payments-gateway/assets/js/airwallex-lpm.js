import {
    getBrowserInfo,
    initAirwallex,
    getLocaleFromBrowserLanguage,
    getSessionId,
} from "./utils";

import {
    createQuote,
} from "./api";

import { handleCurrencySwitchingForKlarna } from './airwallex-klarna';
import { handleCurrencySwitchingForAfterpay } from './airwallex-afterpay';

/** global awxEmbeddedLPMData */
jQuery(function ($) {
    let originalCurrency = '', requiredCurrency = '';
    let currentQuote = {};
    let isLoading = false;

    const switcherMap = {
        airwallex_klarna: handleCurrencySwitchingForKlarna,
        airwallex_afterpay: handleCurrencySwitchingForAfterpay,
    };

    const handleCurrencySwitching = function () {
        const selectedPaymentMethod = getSelectedPaymentMethod();
        const switcher = switcherMap[selectedPaymentMethod];
        if (switcher) {
            return switcher({
                awxEmbeddedLPMData,
                originalCurrency,
                setRequiredCurrency: (val) => requiredCurrency = val,
                displayCurrencySwitchingInfo,
                displayCurrencyIneligibleInfo,
            });
        }

        return true;
    };

    const addCustomDataToCheckoutForm = function (form) {
        $('<input>').prop({
            type: 'hidden',
            id: 'airwallex_device_data',
            name: 'airwallex_device_data',
            value: JSON.stringify(getBrowserInfo(getSessionId())),
        }).appendTo(form);
        $('<input>').prop({
            type: 'hidden',
            id: 'airwallex_origin',
            name: 'airwallex_origin',
            value: window.location.origin,
        }).appendTo(form);
        $('<input>').prop({
            type: 'hidden',
            id: 'airwallex_target_currency',
            name: 'airwallex_target_currency',
            value: requiredCurrency,
        }).appendTo(form);
        $('<input>').prop({
            type: 'hidden',
            id: 'airwallex_browser_language',
            name: 'airwallex_browser_language',
            value: getLocaleFromBrowserLanguage(),
        }).appendTo(form);
    }

    const handleQuoteExpire = function(paymentMethodName) {
        if (Object.keys(currentQuote).length === 0) {
            return Promise.resolve(true);
        } else if (currentQuote && currentQuote.refreshAt && new Date(currentQuote.refreshAt).getTime() >= new Date().getTime()) {
            return Promise.resolve(true);
        } else {
            displayCurrencySwitchingInfo(paymentMethodName, originalCurrency, requiredCurrency);
            showQuoteExpire();
            $('.wc-airwallex-currency-switching-quote-expire-close').off('click');
            $('.wc-airwallex-currency-switching-quote-expire-place-back').off('click');
            $('.wc-airwallex-currency-switching-quote-expire-place-order').off('click');

            return new Promise(function(resolve, reject) {
                $('.wc-airwallex-currency-switching-quote-expire-close, .wc-airwallex-currency-switching-quote-expire-place-back').on('click', function() {
                    reject(false);
                });
                $('.wc-airwallex-currency-switching-quote-expire-place-order').on('click', function() {
                    resolve(true);
                });
            });
        }
    }

    const displayCurrencyIneligibleInfo = function(paymentMethodName, originalCurrency) {
        const { currencyIneligibleCWOff } = awxEmbeddedLPMData.textTemplate;
        const values = {
            '$$payment_method_name$$': paymentMethodName,
            '$$original_currency$$': originalCurrency,
        };
        $('.wc-airwallex-lpm-currency-ineligible-switcher-off .wc-airwallex-alert-box-content')
            .html(getReplacedText(currencyIneligibleCWOff, values));
        $('.wc-airwallex-lpm-currency-ineligible-switcher-off').show();
    }

    const displayCurrencySwitchingInfo = function(paymentMethodName, originalCurrency, requiredCurrency) {
        isLoading = true;
        disablePlaceOrderButton(true);
        disableConfirmButton(true);
        showLoading();
        createQuote(originalCurrency, requiredCurrency).done(function(response) {
            const { quote } = response;
            const { currencyIneligibleCWOff, currencyIneligibleCWOn, conversionRate, convertedAmount } = awxEmbeddedLPMData.textTemplate;
            if (quote) {
                currentQuote = quote;
                const values = {
                    '$$payment_method_name$$': paymentMethodName,
                    '$$original_currency$$': originalCurrency,
                    '$$conversion_rate$$': quote.clientRate,
                    '$$converted_currency$$': quote.targetCurrency,
                    '$$converted_amount$$': quote.targetAmount,
                };
                $('.wc-airwallex-currency-switching-base-amount').html(quote.paymentAmount);
                $('.wc-airwallex-currency-switching-convert-text').html('').append(getReplacedText(conversionRate, values));
                $('.wc-airwallex-currency-switching-converted-amount').html('').append(getReplacedText(convertedAmount, values));
                $('.wc-airwallex-currency-switching-quote-expire-convert-text').html('').append(getReplacedText(currencyIneligibleCWOn, values));
                $('.wc-airwallex-lpm-currency-ineligible-switcher-on .wc-airwallex-alert-box-content').html(getReplacedText(currencyIneligibleCWOn, values));
                $('.wc-airwallex-lpm-currency-ineligible-switcher-on').show();
                showCurrencySwitchingInfo();
                disablePlaceOrderButton(false);
                disableConfirmButton(false);
            } else {
                hideCurrencySwitchingInfo();
                const values = {
                    '$$payment_method_name$$': paymentMethodName,
                    '$$original_currency$$': originalCurrency,
                    '$$converted_currency$$': requiredCurrency,
                };
                $('.woocommerce-checkout .wc-airwallex-alert-box').hide();
                $('.wc-airwallex-lpm-currency-ineligible-switcher-off .wc-airwallex-alert-box-content').html(getReplacedText(currencyIneligibleCWOff, values));
                $('.wc-airwallex-lpm-currency-ineligible-switcher-off').show();
                disablePlaceOrderButton(true);
            }
        }).fail(function(error) {
            hideCurrencySwitchingInfo();
        }).always(function() {
            isLoading = false;
            hideLoading();
        });
    }

    const showLoading = function() {
        $('.wc-airwallex-loader').show();
    }

    const hideLoading = function() {
        $('.wc-airwallex-loader').hide();
    }

    const showCurrencySwitchingInfo = function() {
        $('.wc-airwallex-currency-switching').show();
    }

    const hideCurrencySwitchingInfo = function() {
        $('.wc-airwallex-currency-switching').hide();
    }

    const showQuoteExpire = function() {
        $('.wc-airwallex-currency-switching-quote-expire').show();
        $('.wc-airwallex-currency-switching-quote-expire-mask').show();
    }

    const hideQuoteExpire = function() {
        $('.wc-airwallex-currency-switching-quote-expire').hide();
        $('.wc-airwallex-currency-switching-quote-expire-mask').hide();
    }

    const getReplacedText = function(template, values) {
        for (const key in values) {
            template = template.split(key).join(values[key]);
        }

        return template;
    }

    const getSelectedCountry = function () {
        return jQuery('#billing_country').val();
    }

    const getSelectedPaymentMethod = function () {
        const method = $('.woocommerce-checkout input[name="payment_method"]:checked').attr('id');

        return method ? method.replace('payment_method_', '') : '';
    }

    const disablePlaceOrderButton = function (disable) {
        $('.woocommerce-checkout #place_order').prop('disabled', disable);
    }

    const disableConfirmButton = function (disable) {
        if (disable) {
            $('.wc-airwallex-currency-switching-quote-expire-place-order-mask').show();
        } else {
            $('.wc-airwallex-currency-switching-quote-expire-place-order-mask').hide();
        }
    }

    const registerEventListener = function () {
        $(document.body).on('country_to_state_changed payment_method_selected updated_checkout', function () {
            hideCurrencySwitchingInfo();
            $('.woocommerce-checkout .wc-airwallex-alert-box').hide();
            const canMakePayment = handleCurrencySwitching();
            disablePlaceOrderButton(!canMakePayment || isLoading);
        });

        // temporary disable this feature as it is not working on old version of WooCommerce
        // let count = 0; // to prevent infinite loop in case of unexpected error
        // const firedByAirwallex = 'firedByAirwallex';
        // $(document.body).on('click', '#place_order', function (event, data) {
        //     if (count < 3 && (!data || !data.includes(firedByAirwallex) ) && getSelectedPaymentMethod() in awxEmbeddedLPMData) {
        //         event.preventDefault();
        //         handleQuoteExpire(paymentMethodName).then(function(result) {
        //             $('#place_order').trigger('click', [ firedByAirwallex ]);
        //             count++;
        //         }).catch(function(error) {
        //             console.warn(error);
        //         }).finally(function() {
        //             hideQuoteExpire();
        //         });
        //     }
        // });

        $('form.woocommerce-checkout').on('checkout_place_order', function (event, wcCheckoutForm) {
            if (getSelectedPaymentMethod() in awxEmbeddedLPMData) {
                addCustomDataToCheckoutForm(wcCheckoutForm ? wcCheckoutForm.$checkout_form : 'form.checkout');
            }

            return true;
        });
    }

    if (awxEmbeddedLPMData && awxCommonData) {
        const { env, locale } = awxCommonData;
        initAirwallex(env, locale, () => {});

        originalCurrency = awxEmbeddedLPMData.originalCurrency;
        registerEventListener();

        $('#wc-airwallex-quote-expire-confirm').text($('#place_order').text());
    }
});