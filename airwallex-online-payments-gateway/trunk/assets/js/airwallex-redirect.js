import {
    initAirwallex,
    getLocaleFromBrowserLanguage
} from "./utils";

/** global awxCommonData, awxRedirectElData */
jQuery(function ($) {
    [].forEach.call(document.querySelectorAll('.elementor-menu-cart__container'), function (el) {
        el.style.visibility = 'hidden';
    });
    
    const createElement = () => {
        let getRedirectDataUrl = '';
        let security = '';
        if (location.href.includes('airwallex_payment_method_all') || location.href.includes('airwallex_main')) {
            getRedirectDataUrl = awxCommonData.getApmRedirectData.url;
            security = awxCommonData.getApmRedirectData.nonce;
        } else if (location.href.includes('airwallex_payment_method_wechat') || location.href.includes('airwallex_wechat')) {
            getRedirectDataUrl = awxCommonData.getWechatRedirectData.url;
            security = awxCommonData.getWechatRedirectData.nonce;
        } else if (location.href.includes('airwallex_payment_method_card') || location.href.includes('airwallex_card')) {
            getRedirectDataUrl = awxCommonData.getCardRedirectData.url;
            security = awxCommonData.getCardRedirectData.nonce;
        }

        const urlParams = new URLSearchParams(window.location.search);
        $.ajax({
            url: getRedirectDataUrl + '&security=' + security + '&order_id=' + urlParams.get('order_id'),
            method: 'GET',
            dataType: 'json',
            async: false,
            success: function(response) {
                window.awxRedirectElData = response.data;
            },
            error: function(xhr, status, error) {
                console.error(status, error);
            }
        });

        const {
            elementType,
            elementOptions,
            containerId,
            orderId,
            paymentIntentId,
        } = awxRedirectElData;

        let { confirmationUrl } = awxCommonData;
        const element = Airwallex.createElement(elementType, elementOptions);
        let domElement = element.mount(containerId);
        const waitElementInterval = setInterval(function () {
            if (document.getElementById(containerId) && !document.querySelector(`#${containerId} iframe`)) {
                try {
                    domElement = element.mount(containerId);
                } catch(e) {
                    console.warn(e);
                }
            } else if (document.getElementById(containerId) && document.querySelector(`#${containerId} iframe`)) {
                clearInterval(waitElementInterval);
            }
        }, 1000);
        window.addEventListener('onSuccess', (event) => {
            if (event.target.id !== containerId) {
                return;
            }
            document.getElementById(containerId).style.display = 'none';
            document.getElementById('airwallex-error-message').style.display = 'none';
            var successCheck = document.getElementById('success-check');
            if (successCheck) {
                successCheck.style.display = 'inline-block';
            }
            var successMessage = document.getElementById('success-message');
            if (successMessage) {
                successMessage.style.display = 'block';
            }
            confirmationUrl += confirmationUrl.indexOf('?') !== -1 ? '&' : '?';
            location.href = `${confirmationUrl}order_id=${orderId}&intent_id=${paymentIntentId}&is_airwallex_save_checked=true`;
        });

        window.addEventListener('onError', (event) => {
            document.getElementById('airwallex-error-message').style.display = 'block';
            console.warn(event.detail);
        });
    };
    
    if (awxCommonData) {
        const { env } = awxCommonData;
        const locale = getLocaleFromBrowserLanguage();
        initAirwallex(env, locale, createElement);
    }
});
