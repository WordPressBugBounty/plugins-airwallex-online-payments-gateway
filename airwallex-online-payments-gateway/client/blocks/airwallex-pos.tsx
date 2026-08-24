import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import type { BlockMethodSettings, PaymentMethodComponentProps } from '../types/blocks';

const settings: BlockMethodSettings = getSetting<BlockMethodSettings>('airwallex_pos_data', {});

const title       = settings?.title ?? __('POS', 'airwallex-online-payments-gateway');
const description = settings?.description ?? '';

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
