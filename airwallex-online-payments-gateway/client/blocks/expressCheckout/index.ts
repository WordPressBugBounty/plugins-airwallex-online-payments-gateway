import { registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { airwallexGooglePayOption } from './airwallex-google-pay';
import { airwallexApplePayOption } from './airwallex-apple-pay';

registerExpressPaymentMethod(airwallexApplePayOption);
registerExpressPaymentMethod(airwallexGooglePayOption);
