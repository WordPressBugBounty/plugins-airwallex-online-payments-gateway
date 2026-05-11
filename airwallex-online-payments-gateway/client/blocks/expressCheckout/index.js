import { registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { airwallexGooglePayOption } from './airwallex-google-pay.jsx';
import { airwallexApplePayOption } from './airwallex-apple-pay.jsx';

registerExpressPaymentMethod(airwallexApplePayOption);
registerExpressPaymentMethod(airwallexGooglePayOption);
