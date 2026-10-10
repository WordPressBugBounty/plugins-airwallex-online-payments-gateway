import {
    initAirwallex,
    getLocaleFromBrowserLanguage
} from "./utils";

interface RedirectDataResponse {
    data?: {
        elementType: string;
        elementOptions: Record<string, unknown>;
        containerId: string;
        orderId: string | number;
        paymentIntentId: string;
    };
}

/** global awxCommonData */
jQuery(function ($) {
    [].forEach.call(document.querySelectorAll('.elementor-menu-cart__container'), function (el) {
        (el as HTMLElement).style.visibility = 'hidden';
    });

    const createElement = async () => {
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
        const orderKey = urlParams.get('key') || '';

        const redirectData: RedirectDataResponse = await $.ajax({
            url: getRedirectDataUrl + '&security=' + security + '&order_id=' + urlParams.get('order_id') + '&key=' + encodeURIComponent(orderKey),
            method: 'GET',
            dataType: 'json',
        });

        if (!redirectData?.data) return;
        const {
            elementType,
            elementOptions,
            containerId,
            orderId,
            paymentIntentId,
        } = redirectData.data;

        let { confirmationUrl } = awxCommonData;
        const element = Airwallex!.createElement(elementType as Parameters<NonNullable<typeof Airwallex>['createElement']>[0], elementOptions);
        if (!element) return;
        element.mount(containerId);
        const waitElementInterval = setInterval(function () {
            if (document.getElementById(containerId) && !document.querySelector(`#${containerId} iframe`)) {
                try {
                    element.mount(containerId);
                } catch(e) {
                    console.warn(e);
                }
            } else if (document.getElementById(containerId) && document.querySelector(`#${containerId} iframe`)) {
                clearInterval(waitElementInterval);
            }
        }, 1000);
        window.addEventListener('onSuccess', (event: Event) => {
            if ((event.target as HTMLElement | null)?.id !== containerId) {
                return;
            }
            (document.getElementById(containerId) as HTMLElement).style.display = 'none';
            (document.getElementById('airwallex-error-message') as HTMLElement).style.display = 'none';
            const successCheck = document.getElementById('success-check');
            if (successCheck) {
                successCheck.style.display = 'inline-block';
            }
            const successMessage = document.getElementById('success-message');
            if (successMessage) {
                successMessage.style.display = 'block';
            }
            confirmationUrl += confirmationUrl.indexOf('?') !== -1 ? '&' : '?';
            location.href = `${confirmationUrl}order_id=${orderId}&intent_id=${paymentIntentId}&key=${encodeURIComponent(orderKey)}&is_airwallex_save_checked=true`;
        });

        window.addEventListener('onError', (event: Event) => {
            const detail = (event as CustomEvent<{ error?: { code?: string } }>).detail;
            const errorEl = document.getElementById('airwallex-error-message');
            if (errorEl && detail?.error?.code === 'no_payment_methods') {
                errorEl.textContent = 'No available payment methods.';
            }
            $.ajax({
                url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + orderId,
                method: 'GET',
                dataType: 'json',
            });
            if (errorEl) {
                errorEl.style.display = 'block';
            }
        });
    };

    if (awxCommonData) {
        const { env } = awxCommonData;
        const locale = getLocaleFromBrowserLanguage();
        initAirwallex(env, locale, createElement);
    }
});
