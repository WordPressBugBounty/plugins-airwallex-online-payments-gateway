/**
 * Ambient module declarations for the WooCommerce / WordPress blocks
 * packages. These are externalised at build time by
 * `@woocommerce/dependency-extraction-webpack-plugin` (provided by
 * WP/WC core globals at runtime), so they aren't installed in
 * `node_modules`. Vitest substitutes them via virtual modules
 * (see `vitest.config.ts`); these declarations let `tsc --noEmit`
 * understand the imports.
 *
 * This file deliberately has NO top-level `export` / `import` so it
 * stays an ambient .d.ts script, not a module - that's required for
 * the `declare module 'X'` blocks to be picked up project-wide.
 */

declare module '@woocommerce/blocks-registry' {
    export const registerPaymentMethod: (...args: unknown[]) => void;
    export const registerExpressPaymentMethod: (...args: unknown[]) => void;
}

declare module '@woocommerce/blocks-checkout' {
    type ReactNode = unknown;
    export const ExperimentalOrderMeta: (props: { children?: ReactNode }) => ReactNode;
    export const extensionCartUpdate: (...args: unknown[]) => unknown;
    export const registerCheckoutFilters: (...args: unknown[]) => unknown;
    export const getSetting: <T>(key: string, fallback?: T) => T;
}

declare module '@woocommerce/block-data' {
    export const CART_STORE_KEY: string;
    export const CHECKOUT_STORE_KEY: string;
    export const PAYMENT_STORE_KEY: string;
}

declare module '@woocommerce/settings' {
    export const getSetting: <T>(key: string, fallback?: T) => T;
    export const getSettingWithCoercion: <T>(key: string, fallback?: T) => T;
}

declare module '@wordpress/i18n' {
    export const __: (text: string, domain?: string) => string;
    export const _x: (text: string, context?: string, domain?: string) => string;
    export const _n: (single: string, plural: string, n: number, domain?: string) => string;
    export const sprintf: (fmt: string, ...args: unknown[]) => string;
    export const setLocaleData: (...args: unknown[]) => void;
}

declare module '@wordpress/data' {
    export const select: (storeKey: string) => Record<string, (...args: unknown[]) => unknown>;
    export const dispatch: (storeKey: string) => Record<string, (...args: unknown[]) => unknown>;
    export const subscribe: (cb: () => void) => () => void;
    export const useSelect: <T>(cb: (selectFn: typeof select) => T) => T;
    export const useDispatch: (storeKey: string) => Record<string, (...args: unknown[]) => unknown>;
    export const register: (...args: unknown[]) => unknown;
    export const createReduxStore: (...args: unknown[]) => unknown;
}

declare module '@wordpress/plugins' {
    export const registerPlugin: (
        name: string,
        opts: { render: () => unknown; scope?: string },
    ) => void;
    export const unregisterPlugin: (name: string) => void;
    export const getPlugin: (name: string) => unknown;
}

declare module '@wordpress/element' {
    // Re-export the React surface; webpack's dependency-extraction
    // plugin maps these to `wp.element` at runtime, which mirrors
    // React's API.
    export * from 'react';
}

