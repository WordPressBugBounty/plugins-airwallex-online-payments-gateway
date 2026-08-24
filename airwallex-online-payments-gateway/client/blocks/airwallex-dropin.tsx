import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from 'react';
import { getApmData } from './api';
import type { BlockMethodSettings, PaymentMethodComponentProps } from '../types/blocks';

const settings: BlockMethodSettings = getSetting<BlockMethodSettings>('airwallex_main_data', {});

const title              = settings?.title ?? __('Pay with cards and more', 'airwallex-online-payments-gateway');
const description        = settings?.description ?? '';

const useLogos = () => {
	const [logos, setLogos] = useState<Record<string, string>>({});
	const [maxInlineLogoCount, setMaxInlineLogoCount] = useState(0);

	useEffect(() => {
		getApmData().then(({ data }) => {
			setLogos(data?.active_logos ?? {});
			setMaxInlineLogoCount(data?.max_number_of_logos || 0);
		});
	}, []);
	return { logos, maxInlineLogoCount };
};

const LogoList = ({ logos }: { logos: Record<string, string> }) => (
	<>
		{Object.entries(logos).map(([name, src]) => (
			<img key={name} src={src} alt={title} className="airwallex-card-icon" />
		))}
	</>
);

// `components` is optional for compile-time JSX (the factory is
// invoked as `<AirwallexLabelDropIn />` without props); WC blocks
// injects `props.components.PaymentMethodLabel` at runtime via
// cloneElement.
type AirwallexLabelProps = Partial<PaymentMethodComponentProps>;

const AirwallexLabelDropIn       = (props: AirwallexLabelProps) => {
	const { PaymentMethodLabel } = props.components!;
	const { logos, maxInlineLogoCount } = useLogos();

	return Object.keys(logos).length <= maxInlineLogoCount ? (
		<>
			<PaymentMethodLabel text ={title} />
			<span style              ={{ marginLeft: 'auto', display: 'flex', direction: 'rtl' }}>
				<LogoList logos={logos} />
			</span>
		</>
	) : (
		<PaymentMethodLabel text ={title} />
	);
}

const AirwallexContentDropIn     = () => {
	const { logos, maxInlineLogoCount } = useLogos();

	return Object.keys(logos).length > maxInlineLogoCount ? (
		<>
			<div className       ='airwallex-logo-list' style={{ display: 'flex' }}>
				<LogoList logos={logos} />
			</div>
			<div>{description}</div>
		</>
	) : (
		<>
			<div>{description}</div>
		</>
	);
};

const canMakePayment = (): boolean => {
	return settings?.enabled ?? false;
}

export const airwallexDropInOption = {
	name: settings?.name ?? 'airwallex_main',
	label: <AirwallexLabelDropIn />,
	content: <AirwallexContentDropIn />,
	edit: <AirwallexContentDropIn />,
	canMakePayment: canMakePayment,
	ariaLabel: title,
	supports: {
		features: settings?.supports ?? [],
	}
};
