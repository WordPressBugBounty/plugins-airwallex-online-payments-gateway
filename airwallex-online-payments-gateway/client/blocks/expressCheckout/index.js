import { registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { airwallexGooglePayOption } from './airwallex-google-pay.js';
import { airwallexApplePayOption } from './airwallex-apple-pay.js';

registerExpressPaymentMethod(airwallexApplePayOption);
registerExpressPaymentMethod(airwallexGooglePayOption);
