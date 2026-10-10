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

/**
 * The full payment-method props that WooCommerce blocks pass to
 * `content` / `edit` / `savedTokenComponent` factories. Most fields are
 * `?` because the characterization tests pass minimal `{components: {
 * PaymentMethodLabel }}` shapes for label-only renders, and tightening
 * them would force test-side `as` casts that violate the migration rule
 * "no edits to test logic".
 */
export interface PaymentMethodComponentProps {
    components: {
        PaymentMethodLabel: (props: { text: string }) => ReactNode;
        ValidationInputError?: (props: { errorMessage: string | false }) => ReactNode;
        /**
         * WC's `LoadingMask` wrapper from `@woocommerce/base-components`.
         * Renders `children` and overlays a spinner / aria-busy region
         * when `isLoading` is true. We model only the props the
         * `localPaymentMethods/elements.tsx::AirwallexLpmContent`
         * consumer uses; characterisation-test stubs (which only
         * destructure `children`) stay assignable because every other
         * prop is optional.
         */
        LoadingMask?: (props: {
            isLoading?: boolean;
            screenReaderLabel?: string;
            showSpinner?: boolean;
            children?: ReactNode;
        }) => ReactNode;
    };
    eventRegistration?: {
        onCheckoutSuccess: (cb: (...args: unknown[]) => unknown) => () => void;
        onCheckoutFail: (cb: (...args: unknown[]) => unknown) => () => void;
        onPaymentSetup: (cb: (...args: unknown[]) => unknown) => () => void;
        onCheckoutValidation: (cb: (...args: unknown[]) => unknown) => () => void;
    };
    emitResponse?: {
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
    billing?: {
        /**
         * WooCommerce's billing object is flat (email is top-level, not
         * nested under `address`). `billingData` is the deprecated alias
         * of `billingAddress`; prefer `billingAddress` in new code.
         */
        billingAddress: {
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
    [extra: string]: unknown;
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

/**
 * Generic block-side payment-method settings shape (returned by
 * `@woocommerce/settings`'s `getSetting('airwallex_<x>_data', {})`).
 *
 * Every option-factory file in `client/blocks/**` reads the same
 * `title` / `description` / `name` / `enabled` / `supports` keys and
 * extends them with method-specific fields. Use this superset (with an
 * index signature) so partial test fixtures still satisfy the type
 * while the field reads stay unmodified.
 */
export interface BlockMethodSettings {
    name?: string;
    title?: string;
    description?: string;
    enabled?: boolean;
    supports?: string[];
    icon?: { url?: string; alt?: string };
    [extra: string]: unknown;
}
