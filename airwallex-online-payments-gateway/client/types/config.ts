/**
 * Typed views of the inline-script payloads emitted by
 * `includes/Main.php::enqueueScripts()` and the per-gateway
 * `enqueueScriptsForEmbeddedCard()` / `enqueueScriptsForApm()` /
 * admin `enqueueScripts*()` methods.
 *
 * These are the integration contracts between PHP and JS. Mirror the
 * PHP-side shape exactly; do NOT reshape them at the TS boundary.
 */

export interface AjaxEndpoint {
    url: string;
    nonce: string;
}

export interface AwxCommonData {
    env: 'demo' | 'prod';
    locale: string;
    confirmationUrl: string;
    isOrderPayPage: boolean;
    processOrderPayUrl?: string;
    billingFirstName?: string;
    billingLastName?: string;
    billingEmail?: string;
    billingCity?: string;
    billingCountry?: string;
    billingPostcode?: string;
    billingState?: string;
    billingAddress1?: string;
    billingAddress2?: string;
    getCardData: AjaxEndpoint;
    getApmData: AjaxEndpoint;
    getApmRedirectData: AjaxEndpoint;
    getWechatRedirectData: AjaxEndpoint;
    getCardRedirectData: AjaxEndpoint;
    updateOrderStatusAfterPaymentDecline: AjaxEndpoint;
    getExpressCheckoutData: AjaxEndpoint & {
        hasSubscriptionProduct?: boolean;
        isProductPage?: boolean;
        isVirtualProductPage?: boolean;
        allowedCardNetworks?: AllowedCardNetworks;
        checkout?: ExpressCheckoutCheckout;
    };
    [extra: string]: unknown;
}

export interface EmbeddedCardData {
    autoCapture: boolean;
    errorMessage: string;
    incompleteMessage: string;
    isAccountPage?: boolean;
    isSkipCVCEnabled?: boolean;
    CVCIsNotCompletedMessage: string;
    CVC: string;
    resourceAlreadyExistsMessage?: string;
    currency?: string;
    getCheckoutAjaxUrl: string;
    getTokensAjaxUrl: string;
    getCustomerClientSecretAjaxUrl: string;
    [extra: string]: unknown;
}

export interface EmbeddedLPMData {
    ajaxUrl: string;
    nonce: {
        getStoreCurrency: string;
        createQuoteCurrencySwitcher: string;
    };
    originalCurrency: string;
    availableCurrencies?: string[];
    owningEntity?: string;
    paymentMethodNames?: Record<string, string>;
    textTemplate: {
        currencyIneligibleCWOff: string;
        currencyIneligibleCWOn: string;
        conversionRate: string;
        convertedAmount: string;
    };
    airwallex_klarna?: {
        supportedCountryCurrency?: Record<string, string>;
    };
    airwallex_afterpay?: {
        supportedCountryCurrency?: Record<string, string>;
        supportedEntityCurrencies?: Record<string, string[]>;
    };
    [extra: string]: unknown;
}

export interface AllowedCardNetworks {
    applepay: { oneoff: string[]; recurring: string[] };
    googlepay: { oneoff: string[]; recurring: string[] };
}

export interface ExpressCheckoutCheckout {
    currencyCode: string;
    countryCode: string;
    totalPriceLabel: string;
    autoCapture: boolean;
    requiresPhone?: boolean;
    isVirtualPurchase?: boolean;
    isSkipBillingForVirtual?: boolean;
    subTotal?: number;
    allowedCardNetworks: AllowedCardNetworks;
}

export interface ExpressCheckoutSettings {
    env: 'demo' | 'prod';
    locale: string;
    ajaxUrl: string;
    transactionId: string;
    applePayEnabled: boolean;
    googlePayEnabled: boolean;
    isShowButtonOnProductPage?: boolean;
    button: {
        theme: string;
        buttonType: string;
        height: string;
    };
    merchantInfo: {
        businessName: string;
        accountId?: string;
    };
    nonce: {
        startPaymentSession: string;
        shipping: string;
        updateShipping: string;
        addToCart: string;
        estimateCart: string;
        payment: string;
        checkout: string;
    };
    checkout: ExpressCheckoutCheckout;
    supports?: string[];
    [extra: string]: unknown;
}

export interface MiniCartConfig {
    templateUrl: string;
    [extra: string]: unknown;
}

export interface AdminGeneralSettings {
    apiSettings: {
        connected: boolean;
        connectionFailed?: boolean;
        accountName: { prod: string; demo: string };
        connectButtonText: { connect: string; manage: string };
        useApiKey: { prod: 'yes' | 'no'; demo: 'yes' | 'no' };
        credentials: {
            prod?: { client_id?: string; api_key?: string; webhook_secret?: string };
            demo?: { client_id?: string; api_key?: string; webhook_secret?: string };
        };
        nonce: {
            startConnectionFlow: string;
            connectionTest: string;
        };
        ajaxUrl: {
            startConnectionFlow: string;
            connectionTest: string;
        };
        i18n: {
            connectionTest: {
                requiredFields: string;
                failedMessage: string;
                errorMessage: string;
            };
        };
        isForceSetPaymentFormAsWPPage?: boolean;
    };
    paymentMethodStatus: AjaxEndpoint;
}

export interface AdminApmSettings {
    ajaxUrl: { getApmData: string };
    nonce: { getApmData: string };
}

export interface AdminECSettings {
    locale: string;
    theme: string;
    buttonType: string;
    size: string;
    sizeMap: Record<string, string>;
    apiSettings: {
        ajaxUrl: { activatePaymentMethod: string };
        nonce: { activatePaymentMethod: string };
    };
}

export interface AdminPOSSettings {
    ajaxUrl: { getPOSTerminals: string };
    nonce: { getPOSTerminals: string };
    boundTerminal?: {
        id: string;
        nick_name?: string;
        serial_number?: string;
    };
}
