/**
 * Global declarations for runtime objects the storefront source files
 * implicitly depend on. These are NOT runtime imports - they document
 * the shape of singletons that exist on `window` (or are
 * `wp_localize_script` payloads written to the page).
 */

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
     * `assets/js/airwallex-local.js`.
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
        displayCheckoutError: (form: string | Element, msg: string) => void;
    };

    /**
     * Type alias for the CDN `Airwallex` global, derived from the
     * `airwallex-payment-elements` package's public surface (the same
     * package whose `elements.bundle.min.js` is loaded via the
     * `airwallex-lib-js` script handle in `includes/Main.php`).
     *
     * Using `import('...').loadAirwallex` purely as a type query keeps
     * all runtime code from the package out of `build/*.min.js`, per
     * Constraint #2 of the js-to-ts migration plan.
     */
    type AirwallexSdk = NonNullable<
        Awaited<ReturnType<typeof import('airwallex-payment-elements').loadAirwallex>>
    >;

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

    /* eslint-enable @typescript-eslint/no-empty-interface */
}

export {};
