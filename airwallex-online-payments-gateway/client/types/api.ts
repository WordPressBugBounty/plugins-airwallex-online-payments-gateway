/**
 * Typed contracts for the 29 `wc_ajax_airwallex_*` AJAX endpoints
 * registered in `includes/Main.php`.
 *
 * Anchors each field to the corresponding PHP controller method
 * (e.g. `Controllers/OrderController::getCartDetails()`). These types
 * exist to lock the integration surface in place during the JS->TS
 * migration; they MUST mirror what the PHP side actually sends.
 *
 * Phase B intentionally types only the responses that the front end
 * code under `assets/js/**` and `client/blocks/**` actively consumes.
 * Endpoints exercised purely server-side or only by Playwright are
 * out of scope.
 */

export interface WcAjaxSuccessResponse<T> {
    success: true;
    data?: T;
    [extra: string]: unknown;
}

export interface WcAjaxErrorResponse {
    success: false;
    message?: string;
    [extra: string]: unknown;
}

export type WcAjaxResponse<T> = WcAjaxSuccessResponse<T> | WcAjaxErrorResponse;

export interface CardLogos {
    [logoKey: string]: string;
}

export interface CardDataResponse {
    success: boolean;
    data?: {
        logos?: CardLogos;
    };
}

export interface ApmDataResponse {
    success: boolean;
    data?: {
        active_logos?: Record<string, string>;
        all_logos?: Record<string, string>;
        active_names?: string[];
        all_names?: Record<string, string>;
        max_number_of_logos?: number;
        active_tip?: string;
    };
}

export interface CartOrderInfoTotal {
    label?: string;
    amount: number;
}

export interface CartOrderDisplayItem {
    label: string;
    price: number;
}

export interface CartOrderInfo {
    total: CartOrderInfoTotal;
    displayItems: CartOrderDisplayItem[];
}

export interface CartDetails {
    success: boolean;
    requiresShipping?: boolean;
    countryCode?: string;
    currencyCode?: string;
    orderInfo: CartOrderInfo;
    message?: string;
}

export interface ShippingOption {
    id: string;
    label: string;
    description?: string;
    amount?: number;
}

export interface ShippingResponseShipping {
    shippingMethods: string[];
    shippingOptions: ShippingOption[];
}

export interface ShippingResponse {
    success: boolean;
    shipping?: ShippingResponseShipping;
    cart?: CartDetails;
    message?: string;
}

export interface QuoteResponse {
    quote?: {
        clientRate: string;
        targetCurrency: string;
        targetAmount: string;
        paymentAmount: string;
        paymentCurrency: string;
        refreshAt: string;
    };
}

export interface PlaceOrderResponse {
    result: 'success' | 'failure';
    redirect?: string;
    redirect_url?: string;
    messages?: string;
    payload?: {
        createConsent?: boolean;
        clientSecret: string;
        confirmationUrl: string;
    };
    error?: string | { message?: string };
    paymentMethodId?: string;
    paymentIntent?: string;
    customerId?: string;
    clientSecret?: string;
    currency?: string;
    createConsent?: boolean;
    orderId?: string | number;
    tokenId?: string | number;
    order_id?: string | number;
}

export interface MerchantSession {
    paymentSession?: ApplePayJS.ApplePayPaymentAuthorizedEvent['payment'];
    error?: { message?: string };
}

export interface UpdateOrderStatusResponse {
    success: boolean;
    message?: string;
}
