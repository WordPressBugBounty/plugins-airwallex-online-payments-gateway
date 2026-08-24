import $ from 'jquery';

import type {
	ApmDataResponse,
	CardDataResponse,
	QuoteResponse,
	StoreCurrencyResponse,
	UpdateOrderStatusResponse,
} from '../types/api';

interface SettingsForApi {
	ajaxUrl?: string;
	nonce?: {
		getStoreCurrency?: string;
		createQuoteCurrencySwitcher?: string;
	};
}

const getAjaxURL = (endpoint: string, settings: SettingsForApi): string => {
	return (settings.ajaxUrl as string)
		.toString()
		.replace('%%endpoint%%', 'airwallex_' + endpoint);
};

export const getStoreCurrency = (settings: SettingsForApi): JQuery.jqXHR<StoreCurrencyResponse> => {
    return $.ajax({
        type: 'GET',
        data: {
            security: settings?.nonce?.getStoreCurrency,
        },
        url: getAjaxURL('get_store_currency', settings),
    }) as JQuery.jqXHR<StoreCurrencyResponse>;
};

export const createQuote = (
    originalCurrency: string,
    requiredCurrency: string,
    settings: SettingsForApi,
): JQuery.jqXHR<QuoteResponse> => {
    return $.ajax({
        type: 'POST',
        data: {
            payment_currency: originalCurrency,
            target_currency: requiredCurrency,
            security: settings?.nonce?.createQuoteCurrencySwitcher,
        },
        url: getAjaxURL('currency_switcher_create_quote', settings),
    }) as JQuery.jqXHR<QuoteResponse>;
};

export const updateOrderStatusAfterPaymentDecline = (
	orderId: string | number,
): JQuery.jqXHR<UpdateOrderStatusResponse> => {
	return $.ajax({
		url: awxCommonData.updateOrderStatusAfterPaymentDecline.url + '&security=' + awxCommonData.updateOrderStatusAfterPaymentDecline.nonce + "&order_id=" + orderId,
		method: 'GET',
		dataType: 'json'
	}) as JQuery.jqXHR<UpdateOrderStatusResponse>;
}

export const getCardData = (): JQuery.jqXHR<CardDataResponse> => {
    return $.ajax({
        url: awxCommonData.getCardData.url + '&security=' + awxCommonData.getCardData.nonce,
        method: 'GET',
        dataType: 'json'
    }) as JQuery.jqXHR<CardDataResponse>;
}

export const getApmData = (): JQuery.jqXHR<ApmDataResponse> => {
    return $.ajax({
        url: awxCommonData.getApmData.url + '&security=' + awxCommonData.getApmData.nonce,
        method: 'GET',
        dataType: 'json'
    }) as JQuery.jqXHR<ApmDataResponse>;
}
