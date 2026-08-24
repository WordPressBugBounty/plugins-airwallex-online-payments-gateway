/**
 * Global declarations for runtime objects the storefront source files
 * implicitly depend on. These are NOT runtime imports - they document
 * the shape of singletons that exist on `window` (or are
 * `wp_localize_script` payloads written to the page).
 */

import type { Payment } from '@airwallex/components-sdk';

import type {
    AwxCommonData,
    EmbeddedCardData,
    EmbeddedLPMData,
    ExpressCheckoutSettings,
    MiniCartConfig,
    AdminGeneralSettings,
    AdminApmSettings,
    AdminECSettings,
    AdminPOSSettings,
} from './config';

declare global {
    /* eslint-disable @typescript-eslint/no-empty-interface */

    /**
     * Re-export the inline-script payload types as global type aliases
     * so test files can `value as AwxCommonData` without an extra
     * `import type { ... } from '../../client/types/config'` boilerplate
     * line at the top of every spec.
     */
    type AwxCommonData = import('./config').AwxCommonData;
    type EmbeddedCardData = import('./config').EmbeddedCardData;
    type EmbeddedLPMData = import('./config').EmbeddedLPMData;
    type ExpressCheckoutSettings = import('./config').ExpressCheckoutSettings;
    type MiniCartConfig = import('./config').MiniCartConfig;
    type AdminGeneralSettings = import('./config').AdminGeneralSettings;
    type AdminApmSettings = import('./config').AdminApmSettings;
    type AdminECSettings = import('./config').AdminECSettings;
    type AdminPOSSettings = import('./config').AdminPOSSettings;

    /**
     * Inline-script payloads written to the page by
     * `includes/Main.php::enqueueScripts()` (storefront) and
     * `includes/Admin/Settings.php` (admin).
     */
    var awxCommonData: AwxCommonData;
    var awxEmbeddedCardData: EmbeddedCardData;
    var awxEmbeddedLPMData: EmbeddedLPMData;
    var awxExpressCheckoutSettings: ExpressCheckoutSettings;
    var awxMiniCartConfig: MiniCartConfig;
    var awxMiniCartEnabled: boolean | undefined;
    var awxAdminSettings: AdminGeneralSettings;
    var awxAdminApmSettings: AdminApmSettings;
    var awxAdminECSettings: AdminECSettings;
    var awxAdminPOSSettings: AdminPOSSettings;

    /**
     * `wc_order_attribution` is the `@woocommerce/order-attribution`
     * client; only `getAttributionData` is consumed by our code.
     */
    var wc_order_attribution: {
        getAttributionData: () => Record<string, string>;
    } | undefined;

    /**
     * The `airwallex-common-js` global from
     * `assets/js/airwallex-local.ts`. Webpack bundles the source file into
     * `assets/js/airwallex-local.js` (route a) and exposes the singleton
     * via `window.AirwallexClient = AirwallexClient` at the end of the
     * source — see `assets/js/airwallex-local.ts`.
     */
    var AirwallexClient: {
        getCustomerInformation: (fieldId: string, parameterName: string) => string;
        getCardHolderName: () => string;
        getBillingInformation: () => {
            address: {
                city: string;
                country_code: string;
                postcode: string;
                state: string;
                street: string;
            };
            first_name: string;
            last_name: string;
            email: string;
        };
        ajaxGet: (url: string, callback: (data: unknown) => void) => void;
        displayCheckoutError: (form: string | HTMLElement, msg: string) => void;
    };

    /**
     * Union of every Airwallex element kind the storefront mounts via
     * `Airwallex.createElement(...)`. Each branch extends
     * `Payment.ElementBaseType`, so `.mount(...)` / `.unmount()` /
     * `.destroy()` resolve uniformly when the runtime narrows by string
     * key (see `airwallex-redirect.ts`'s parametric `createElement` call
     * for the only consumer that needs the widened return).
     */
    type AnyAirwallexElement =
        | Payment.CardElementType
        | Payment.CardNumberElementType
        | Payment.ExpiryDateElementType
        | Payment.CvcElementType
        | Payment.ApplePayButtonElementType
        | Payment.GooglePayButtonElementType
        | Payment.DropInElementType;

    /**
     * Shape of the `window.Airwallex` runtime global. The CDN script
     * (`elements.bundle.min.js`, loaded via the `airwallex-lib-js`
     * handle in `includes/Main.php::registerScripts()`) exposes a
     * SYNCHRONOUS `createElement(type, opts)`. The modern
     * `@airwallex/components-sdk` package's published signature is
     * `Promise<ElementTypes[T]>`; we pin to the CDN's sync shape here
     * to avoid changing any runtime behaviour during the migration.
     *
     * Element-typed overloads consume `Payment.*` types from
     * `@airwallex/components-sdk`. The package is a `devDependency` and
     * every reference uses `import type`, so zero runtime code from the
     * package ends up in any webpack bundle in `build/` (Constraint #2
     * of the js-to-ts migration plan). Non-element methods stay loose
     * because the existing call sites pass `as any` payloads anchored
     * to the AJAX layer; tightening them is a separate migration step.
     */
    interface AirwallexRuntime {
        init(options?: unknown): void | Promise<unknown>;
        createElement(type: 'card', options?: Payment.CardElementOptions): Payment.CardElementType | null;
        createElement(type: 'cardNumber', options?: Payment.CardNumberElementOptions): Payment.CardNumberElementType | null;
        createElement(type: 'expiry', options?: Payment.ExpiryDateElementOptions): Payment.ExpiryDateElementType | null;
        createElement(type: 'cvc', options?: Payment.CvcElementOptions): Payment.CvcElementType | null;
        createElement(type: 'applePayButton', options?: Payment.ApplePayButtonOptions): Payment.ApplePayButtonElementType | null;
        createElement(type: 'googlePayButton', options?: Payment.GooglePayButtonOptions): Payment.GooglePayButtonElementType | null;
        createElement(type: 'dropIn', options?: Payment.DropInElementOptions): Payment.DropInElementType | null;
        createElement(type: string, options?: unknown): AnyAirwallexElement | null;
        destroyElement(type: string): boolean | void;
        getElement(type: string): AnyAirwallexElement | null;
        confirmPaymentIntent(data: unknown): Promise<unknown>;
        confirmPaymentIntentWithSavedCard(data?: unknown): Promise<unknown>;
        createPaymentMethod(data: unknown): Promise<unknown>;
        createPaymentConsent(data: unknown): Promise<unknown>;
        getPaymentIntent(intentId: string, clientSecret: string): Promise<unknown>;
        getDeviceFingerprint(options?: unknown): Promise<string>;
    }

    /**
     * Backwards-compatible alias for the runtime shape. Existing test
     * setup and characterisation tests reference `AirwallexSdk` (see
     * `setupTests.ts` and `assets/js/utils.test.ts`); keep the name
     * stable so no test files have to move when typing changes here.
     */
    type AirwallexSdk = AirwallexRuntime;

    /**
     * The CDN `Airwallex` global, loaded via the `airwallex-lib-js`
     * handle from `https://static.airwallex.com/.../elements.bundle.min.js`.
     *
     * Optional because `assets/js/utils.js::initAirwallex` polls
     * `window.Airwallex` until it appears, and the corresponding tests
     * delete and re-install the global between runs.
     */
    var Airwallex: AirwallexSdk | undefined;

    interface Window {
        jQuery: typeof import('jquery');
        $: typeof import('jquery');
        AirwallexClient: typeof AirwallexClient;
        Airwallex?: AirwallexSdk;
        awxCommonData: AwxCommonData;
        awxEmbeddedCardData: EmbeddedCardData;
        awxEmbeddedLPMData: EmbeddedLPMData;
        awxExpressCheckoutSettings: ExpressCheckoutSettings;
        awxMiniCartConfig: MiniCartConfig;
        awxMiniCartEnabled?: boolean;
        awxAdminSettings: AdminGeneralSettings;
        awxAdminApmSettings: AdminApmSettings;
        awxAdminECSettings: AdminECSettings;
        awxAdminPOSSettings: AdminPOSSettings;
        wc_order_attribution?: {
            getAttributionData: () => Record<string, string>;
        };
        // `ApplePaySession` is the entry point for Apple Pay on the web,
        // typed by `@types/applepayjs` as a global class. The
        // `expressCheckout/utils.js::deviceSupportApplePay()` feature
        // detection guards on its presence, and tests delete / reinstall
        // it between runs - so on `Window` it is optional (and `unknown`
        // so test-side partial mocks assign without further casts).
        ApplePaySession?: unknown;
    }

    /**
     * `userLanguage` is an IE-only property; some helpers fall back to
     * it (`navigator.language || navigator.userLanguage`).
     */
    interface Navigator {
        userLanguage?: string;
    }

    /**
     * `documentMode` is an IE-only property used by some IE detection
     * paths (`!!document.documentMode`).
     */
    interface Document {
        documentMode?: number;
    }

    /**
     * jQuery plugin augmentations. The methods below are runtime-supplied
     * by WooCommerce core (`jquery.blockUI`, `WC().scroll_to_notices`)
     * and must NOT be imported - they live on the global jQuery instance
     * once WC core loads. We declare them here so the source code can
     * call `$(form).unblock()` etc. without inline casts at every site.
     *
     * `@types/jquery` declares `JQueryStatic` and `JQuery` as ambient
     * globals (not inside `declare module 'jquery'`), so the augmentations
     * must also live in `declare global` to merge with them.
     */
    /* eslint-disable @typescript-eslint/no-empty-interface */
    interface JQueryStatic {
        blockUI: (options?: unknown) => void;
        unblockUI: (options?: unknown) => void;
        scroll_to_notices: (target?: unknown) => void;
    }
    interface JQuery<TElement = HTMLElement> {
        block(options?: unknown): JQuery<TElement>;
        unblock(options?: unknown): JQuery<TElement>;
    }
    /* eslint-enable @typescript-eslint/no-empty-interface */
}

export {};
