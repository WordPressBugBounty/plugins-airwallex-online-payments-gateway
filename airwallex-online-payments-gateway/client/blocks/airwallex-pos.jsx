import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';

const settings = getSetting('airwallex_pos_data', {});

const title       = settings?.title ?? __('POS', 'airwallex-online-payments-gateway');
const description = settings?.description ?? '';

const AirwallexLabel             = (props) => {
	const { PaymentMethodLabel } = props.components;

	return <PaymentMethodLabel text ={title} />;
}

const AirwallexContent = (props) => {
	return <div>{description}</div>;
};

const canMakePayment = () => {
	return settings?.enabled ?? false;
}

export const airwallexPOSOption = {
	name: settings?.name ?? 'airwallex_pos',
	label: <AirwallexLabel />,
	content: <AirwallexContent />,
	edit: <AirwallexContent />,
	canMakePayment: canMakePayment,
	ariaLabel: title,
	supports: {
		features: settings?.supports ?? [],
	}
};
