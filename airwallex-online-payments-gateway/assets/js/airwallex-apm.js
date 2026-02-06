jQuery(function ($) {
    let cacheApmData = null;

    const renderApmLogos = (apmData, $apmLogoElement) => {
        const logos = apmData?.active_logos || {};
        const maxNumberOfLogos = apmData?.max_number_of_logos || 5;
        const keys = Object.keys(logos);

        if (!keys.length) return;

        const logosHtml = keys.map(key => `<img src="${logos[key]}" class="airwallex-card-icon" title="${key}">`).join('');

        if (keys.length <= maxNumberOfLogos) {
            $apmLogoElement.replaceWith(logosHtml);
        } else {
            $("#awx-apm-logos-in-content").html(`<div class="airwallex-logo-list">${logosHtml}</div>`);
        }
    };

    const showApmLogos = () => {
        const $apmLogoElement = $('#awx-apm-logos-classic');
        if (!$apmLogoElement.length) return;

        if (cacheApmData) {
            return renderApmLogos(cacheApmData, $apmLogoElement);
        }

        $.ajax({
            url: `${awxCommonData.getApmData.url}&security=${awxCommonData.getApmData.nonce}`,
            method: 'GET',
            dataType: 'json',
            success(response) {
                const apmData = response?.data;
                if (!apmData) return;

                cacheApmData = apmData;
                renderApmLogos(apmData, $apmLogoElement);
            }
        });
    };

    $(document).on('updated_checkout', showApmLogos);
});
