import { __ } from '@wordpress/i18n';
import type { PaymentMethodComponentProps } from '../types/blocks';

const title       = __('Express Checkout', 'airwallex-online-payments-gateway');
const description = '';

// `components` is optional for compile-time JSX (the factory is
// invoked as `<AirwallexLabel />` without props); WC blocks injects
// `props.components.PaymentMethodLabel` at runtime via cloneElement.
type AirwallexLabelProps = Partial<PaymentMethodComponentProps>;

const AirwallexLabel             = (props: AirwallexLabelProps) => {
	const { PaymentMethodLabel } = props.components!;

	return <PaymentMethodLabel text ={title} />;
}

const AirwallexContent = () => {
	return <div>{description}</div>;
};

const canMakePayment = (): boolean => {
	return false;
}

export const airwallexExpressCheckoutOption = {
	name: 'airwallex_express_checkout',
	label: <AirwallexLabel />,
	content: <AirwallexContent />,
	edit: <AirwallexContent />,
	canMakePayment: canMakePayment,
	ariaLabel: title,
	supports: {
		features: [],
	}
};
