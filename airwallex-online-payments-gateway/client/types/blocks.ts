/**
 * Typed shapes for `@woocommerce/blocks-registry` payment-method
 * configurations and the `@woocommerce/blocks-checkout` slot props
 * the storefront blocks layer registers.
 *
 * The runtime blocks API is not strongly typed by WooCommerce, so we
 * model just the surface our `client/blocks/**` factories actually
 * produce/consume.
 */

import type { ReactNode } from 'react';

export interface PaymentMethodComponentProps {
    components: {
        PaymentMethodLabel: (props: { text: string }) => ReactNode;
        ValidationInputError?: (props: { errorMessage: string | false }) => ReactNode;
        LoadingMask?: (props: {
            isLoading?: boolean;
            screenReaderLabel?: string;
            children?: ReactNode;
        }) => ReactNode;
    };
    eventRegistration: {
        onCheckoutSuccess: (cb: (...args: unknown[]) => unknown) => () => void;
        onCheckoutFail: (cb: (...args: unknown[]) => unknown) => () => void;
        onPaymentSetup: (cb: (...args: unknown[]) => unknown) => () => void;
        onCheckoutValidation: (cb: (...args: unknown[]) => unknown) => () => void;
    };
    emitResponse: {
        responseTypes: {
            SUCCESS: 'success';
            ERROR: 'error';
            FAIL: 'failure';
        };
        noticeContexts: {
            PAYMENTS: 'wc/payment-area';
            EXPRESS_PAYMENTS: 'wc/express-payment-area';
        };
    };
    billing: {
        billingData: {
            first_name: string;
            last_name: string;
            email: string;
            city: string;
            country: string;
            postcode: string;
            state: string;
            address_1: string;
            address_2: string;
        };
        cartTotal: { value: number };
        cartTotalItems: { label: string; value: number }[];
        currency: { code: string; minorUnit: number };
    };
    shippingData?: {
        needsShipping: boolean;
    };
    activePaymentMethod?: string;
    onError?: (msg: string) => void;
    setExpressPaymentError?: (msg: string) => void;
    token?: string;
}

export interface PaymentMethodConfiguration {
    name: string;
    label?: ReactNode;
    content: ReactNode;
    edit: ReactNode;
    canMakePayment: (...args: unknown[]) => boolean;
    ariaLabel?: string;
    paymentMethodId?: string;
    savedTokenComponent?: ReactNode;
    supports: {
        features: string[];
    };
}

export interface OrderMetaSlotProps {
    cart: {
        cartTotal: { value: number };
        currency: { code: string };
    };
    extensions: Record<string, unknown>;
}
