import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { InlineCard, AirwallexSaveCard } from './elements.js';

const settings = getSetting('airwallex_card_data', {});
const title       = settings?.title || __('Card', 'airwallex-online-payments-gateway');
const description = settings?.description;
const logos       = settings?.icons ?? {};
const cardInformationMessage = __('Card information', 'airwallex-online-payments-gateway');
const savePaymentMessage = __('Save payment information to my account for future purchases', 'airwallex-online-payments-gateway');

const AirwallexLabelCard         = (props) => {
	const { PaymentMethodLabel } = props.components;

	return (
		<>
			<PaymentMethodLabel text ={title} />
			<span style              ={{ marginLeft: 'auto', display: 'flex', direction: 'rtl' }}>
				{Object.entries(logos).map(([name, src]) => {
					return (
						<img key     ={name} src={src} alt={title} className='airwallex-card-icon' style={{ marginRight: '5px' }} />
					);
				})}
			</span>
		</>
	);
}

const AirwallexContentCard       = (props) => {

	if (settings?.enabled === false) {
		return null;
	}

	let card = settings.checkout_form_type == 'inline' ? (
		<>
			<div style={{ color: 'rgba(20, 23, 26, 1)' }}>{cardInformationMessage}</div>
			{description && <p>{description}</p>}
			<InlineCard settings ={settings} props={props} />
			{ settings.is_logged_in && settings.is_save_card_enabled && ! settings.is_subscription && (
				<div className="line save">
					<input type="checkbox" id="airwallex-save" />
					<label htmlFor="airwallex-save" style={{ color: 'rgba(20, 23, 26, 1)', marginLeft: '6px'}}>
						{savePaymentMessage}
					</label>
				</div>
			)}
		</>
	) : (
		<div>{description}</div>
	);
	return card;
};

const canMakePayment = () => {
	return settings?.enabled ?? false;
}

export const airwallexCardOption = {
	name: settings?.name ?? 'airwallex_card',
	label: <AirwallexLabelCard />,
	content: <AirwallexContentCard />,
	edit: <AirwallexContentCard />,
	savedTokenComponent: <AirwallexSaveCard settings ={settings} />,
	canMakePayment: canMakePayment,
	ariaLabel: title,
	supports: {
		features: settings?.supports ?? [],
	}
};
