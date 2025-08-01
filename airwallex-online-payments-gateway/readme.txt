=== Airwallex Online Payments Gateway ===
Contributors: airwallex
Tags: credit card, airwallex, payments, wechat, alipay, apple pay
Requires at least: 4.5
Tested up to: 6.8.2
Stable tag: 1.23.2
Requires PHP: 7.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Author URI: https://www.airwallex.com
Attributions: airwallex

Accept credit/debit card, Apple Pay, Google Pay, and 30+ local payment methods on your WooCommerce Store with Airwallex.

== Description ==

## POWER YOUR CHECKOUT WITH AIRWALLEX ONLINE PAYMENTS
Power your WooCommerce store checkout with Airwallex Online payments’ multi-currency checkout, with its like-for-like settlement and 30+ payment methods, to get paid by your global customers in an easier, faster and more cost-effective way. 

See the [installation guide](https://www.airwallex.com/docs/online-payments__plugins__woocommerce__install-the-woocommerce-plugin) for how to install and configure this plugin. 

== WHY CHOOSE AIRWALLEX ==

#### Improve conversion by localizing your checkout experience, offering all the local payment methods that your customers use, and supporting multi-currency checkout
Airwallex allows your customers to pay you through all mainstream payment methods, including major card schemes (Visa, Mastercard, American Express, UnionPay), mobile wallets like Apple Pay and Google Pay, and 30+ local payment methods across APAC and Europe such as WeChat Pay & Alipay in China, iDeal, Bancontact, GiroPay and Sofort in Europe, and DANA and GrabPay in Southeast Asia.

#### Improve your profit margins by cutting out unnecessary fees by getting like-for-like settlement in 7+ currencies
Accept 100+ currencies and get like-for-like settlements in 7+ currencies, including USD, CAD, GBP, EUR and AUD, directly into your Airwallex multi-currency wallet. Avoid forced FX conversions to your home currency, and instead use the settled funds to pay for your SaaS subscriptions through Airwallex Borderless Cards, or your global suppliers using Airwallex Transfers. You can also convert these collections back to your home currency at very low FX rates with Airwallex.

#### Enable a faster checkout experience for repeat customers by saving their card details
Increase your checkout conversion by removing the friction of having your customers re-enter their card details when making repeat purchases. Now they can save their card number on file for faster future payments.

== PLUGIN FEATURES ==

**Support for all available Airwallex payment methods:** 30+ payment methods live including major schemes, wallets and bank transfers. Full list [here](https://www.airwallex.com/docs/online-payments__overview)
**Checkout in 100+ currencies and enjoy like-for-like settlements in 7+ currencies:** See more details on supported processing & settlement currencies for each payment method [here](https://www.airwallex.com/docs/online-payments__overview)
**Recurring payments:** Compatible with WooCommerce Subscriptions for recurring payments with Visa, Mastercard, Apple Pay, Google Pay, and more.
**Local, in-house support**, in UK, Netherlands, Australia, Hong Kong, China, and Singapore: You will always be helped by someone who knows our products intimately
Simple and secure: Reduce chargebacks with our 3DS fraud engine, customizable risk settings and built-in dispute management
**No redirect:** The plugin supports checkout directly on the merchant store website without the need to redirect customers away from the website to make a payment
**Mult-lingual error codes:** Support for multilingual error codes during the checkout process
**Transparent pricing:** Airwallex has no setup fees, no monthly fees, and no hidden costs.  Merchant pay per transaction
**Customizable checkout:** Take control over the look and feel of your checkout. 
**Support for partial payment refunds**, for WooCommerce 2.2 and above

== Installation ==

**Configure and go live:** Please refer to our [installation guide](https://www.airwallex.com/docs/online-payments__plugins__woocommerce__install-the-woocommerce-plugin) for how to install and configure this plugin.

**Get in touch:** Sign up for an account at [www.airwallex.com](https://www.airwallex.com). We provide sandbox testing accounts on an as-needed basis. Once your account is activated, please contact [support@airwallex.com](https://www.airwallex.com/docs/support@airwallex.com) to request a demo account for testing. Provide your Airwallex registered company name and the payment methods you want to test using the demo account.
If you encounter any unexpected behavior, first check your configuration against the installation guide and retry. Contact [support@airwallex.com](https://www.airwallex.com/docs/support@airwallex.com) if you need any help.

== ABOUT AIRWALLEX ==

Airwallex is a global payments platform with a mission to empower businesses of all sizes to grow without borders, and by doing so, contribute to the global economy. With technology at its core, Airwallex has built a financial infrastructure and platform to help businesses manage online payments, treasury and payout globally, without the constraints of the traditional financial system. Airwallex has raised over US$900 million since it was established in 2015, and is backed by world-leading investors. Today, the business operates with a team of over 1,200 employees across 19 locations globally.

=== Use of Airwallex Services  ==

This plugin utilizes [Airwallex](https://www.airwallex.com/) to process your payments. Your order and payment information will be securely transmitted to Airwallex for processing.

For more details, please review [Airwallex's Terms and Policies](https://www.airwallex.com/terms/sign-up-terms). 

== Frequently Asked Questions ==

= Where can I find the non-compiled version of your javascript?  =
The non-compressed javascript files can be found under the ```assets/js``` folder and the ```client/blocks``` folder.

We use the [@wordpress/scripts](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) as our build tool.

== Changelog ==

= 1.23.2 =
* Release Date - 1 August 2025*

* Fix - Enhanced the stability and reliability of all payment methods.

= 1.23.1 =
* Release Date - 28 July 2025*

* Fix - Removed invalid saved cards for users after switching the Airwallex account

= 1.23.0 =
* Release Date - 16 July 2025*

* Add - Afterpay standalone with currency switcher

= 1.22.0 =
* Release Date - 18 June 2025*

* Add - Display cards in the saved cards list that were previously saved when creating subscription orders
* Add - Display product options to shoppers during checkout through the All Payment Methods payment flow

= 1.21.0 =
* Release Date - 06 June 2025*

* Add - Support payment for subscription orders using saved cards

= 1.20.0 =
* Release Date - 14 May 2025*

* Add - Enhanced order details display while paying with the Additional Payment Methods
* Add - Support for 4-digit CVC with AMEX cards
* Add - Improved connection flow, allowing merchants to manually input the API key if OAuth cannot be completed
* Fix - Resolved issue where shoppers were not redirected to the order received page after a successful payment using the Additional Payment Methods

= 1.19.0 =
* Release Date - 6 May 2025*

* Add - Add ability for shoppers to save cards

= 1.18.0 =
* Release Date - 28 April 2025*

* Add - Support subscriber payment method changes

= 1.17.5 =
* Release Date - 18 March 2025*

* Fix - Fixed an issue in Airwallex customer ID generation

= 1.17.4 =
* Release Date - 17 March 2025*

* Fix - Fixed an issue where the order status was updated twice after payment, preventing duplicate order notes

= 1.17.3 =
* Release Date - 3 March 2025*

* Fix - Fix checkout issue when WooCommerce Subscription is not installed

= 1.17.2 =
* Release Date - 18 February 2025*

* Fix - Remove auto focus from the native card payment method

= 1.17.1 =
* Release Date - 13 February 2025*

* Fix - Express Checkout Button style issue

= 1.17.0 =
* Release Date - 13 February 2025*

* Add - Improved connection flow for a smoother and easier store integration with your Airwallex account

= 1.16.1 =
* Release Date - 8 February 2025*

* Fix - Klarna payment failed due to a discrepancy between the order amount and the total of order items

= 1.16.0 =
* Release Date - 22 January 2025*

* Add - Support change payment gateway for WooCommerce Subscriptions

= 1.15.1 =
* Release Date - 17 January 2025*

* Add - Support for Diners and Discover Card Schema

= 1.15.0 =
* Release Date - 13 November 2024*

* Add - Descriptor configuration option under the API Setting and ensure it is applied across all payment methods

= 1.14.1 =
* Release Date - 09 October 2024*

* Fix - Klarna standalone payment method is incompatible with older WooCommerce versions
* Fix - In certain cases, the payment sheets for the 'All payment methods' do not render properly

= 1.14.0 =
* Release Date - 24 September 2024*

* Add - 3D Secure authentication data in order notes
* Add - Local payment methods redirect enhancement
* Fix - Second address line is not including in the payment intent order shipping address

= 1.13.0 =
* Release Date - 04 September 2024*

* Add - Automated domain registration for Apple Pay

= 1.12.2 =
* Release Date - 20 August 2024*

* Fix - Order summary is incorrect when the shopper uses WooCommerce order pay under 'All Payment Methods'

= 1.12.1 =
* Release Date - 18 July 2024*

* Fix - Conflict with the Advanced Coupons for WooCommerce plugin on Woo block checkout page

= 1.12.0 =
* Release Date - 15 July 2024*

* Add - Remove the requirement for a shipping address in Apple Pay Express Checkout for orders that do not require shipping

= 1.11.2 =
* Release Date - 11 July 2024*

* Add - Security enhancements

= 1.11.1 =
* Release Date - 05 July 2024*

* Add - Support for WordPress 6.5.5

= 1.11.0 =
* Release Date - 06 June 2024*

* Add - AVS result in the order note for card transactions

= 1.10.1 =
* Release Date - 13 May 2024*

* Compatibility enhancement

= 1.10.0 =
* Release Date - 10 May 2024*

* Fix - Compatibility issue with frontend optimization plugins
* Add - Make WordPress shortcode payment template default to new client

= 1.9.3 =
* Release Date - 23 April 2024*

* Add - Update the link for Klarna's list of compatible countries

= 1.9.2 =
* Release Date - 11 April 2024*

* Fix - The pop-up notification for the currency switcher quote expiration is appearing in an unexpected location

= 1.9.1 =
* Release Date - 09 April 2024*

* Fix - Payment cannot proceed if the name of the shipping method surpass the allowable character limit

= 1.9.0 =
* Release Date - 09 April 2024*

* Add - Klarna standalone with currency switcher
* Fix - Express Checkout display issue on small screen

= 1.8.0 =
* Release Date - 25 March 2024*

* Add - Apple Pay express checkout button enhancement
* Add - Sign up instructions for new merchants

= 1.7.0 =
* Release Date - 06 March 2024*

* Add - Google Pay express checkout onboarding enhancement
* Add - Cache enhancement
* Fix - Broken card method when no payment methods available

= 1.6.1 =
* Release Date - 07 February 2024*

* Fix - Issue with WooCommerce order pay page

= 1.6.0 =
* Release Date - 01 February 2024*

* Add - Apple Pay express checkout
* Add - Support for multiple subscriptions
* Fix - Conflict with Klarna Checkout For WooCommerce plugin

= 1.5.1 =
* Release Date - 18 January 2024*

* Fix - Checkout issue

= 1.5.0 =
* Release Date - 16 January 2024*

* Add - Google Pay express checkout
* Add - Plugin settings UI improvement
* Add - Klarna redirect enhancement
* Fix - Shipping cost is not visible for the all payment methods page
* Fix - Remote logging warning message in the log file

= 1.4.0 =
* Release Date - 21 December 2023*

* Add - Support for High Performance Order Storage(HPOS)
* Fix - Card method description issue
* Fix - Remote logging warning message

= 1.3.1 =
* Release Date - 09 November 2023*

* Add - changelog.

= 1.3.0 =
* Release Date - 26 October 2023*

* Add - Support for WooCommerce Cart and Checkout Blocks.

= 1.2.13 =
* Release Date - 18 October 2023*

* Fix - Payment intent product list contains product item with negative unit price.

= 1.2.12 =
* Release Date - 25 September 2023*

* Fix - Theme compatibility issues.
*       New option in the Airwallex API settings is available to change the payment form template.
*       Three payment method pages with corresponding shortcodes have been added.
*       The shortcode can accept arguments 'class' and 'style' to customize the appearance of the payment form.

= 1.2.11 =
* Release Date - 18 September 2023*

* Fix - Empty street field for card payment
* Fix - Sum of all order product amounts is less than the payment intent amount for Klarna

= 1.2.10 =
* Release Date - 06 September 2023*

* Add - Option to toggle remote logging on or off
* Fix - Waring message when debug mode is on
* Fix - When using Klarna as the payment method, the email field is missing and needs to be provided
* Fix - Creation of duplicate refund items during the refund process

= 1.2.9 =
* Release Date - 25 August 2023*

* Include shipping fee in payment intent

= 1.2.8 =
* Release Date - 07 August 2023*

* Klarna adaptions

= 1.2.7 =
* Release Date - 20 July 2023*

* Enhanced logging

= 1.2.6 =
* Release Date - 03 July 2023*

* Enhanced Caching

= 1.2.5 =
* Release Date - 28 June 2023*

* Enhanced locale support

= 1.2.4 =
* Release Date - 23 May 2023*

* Optimization webhook handling
* Adaption icons in checkout

= 1.2.3 =
* Release Date - 10 Apr 2023*

* Additional logging functionality

= 1.2.2 =
* Release Date - 23 Dec 2022*

* Make billing info Optional
* Reuse intent if nothing change

= 1.2.1 =
* Release Date - 25 Now 2022*

* Relocate the sandbox toggle to api setting page
* Provide details for risk control purpose

= 1.2.0 =
* Release Date - 17 October 2022*

* Implementation of Drop-In elements
* IDs in manual payment URLs for safer sessions

= 1.1.8 =
* Release Date - 11 July 2022*

* Replacing the latest airwallex checkout file

= 1.1.7 =
* Release Date - 27 April 2022*

* Warning log if cache-directory permissions are not correctly set
* Cron interval configurable
* Added description for sandbox setting

= 1.1.6 =
* Release Date - 14 April 2022*

* Implementation of cronjob for handling non reflected payments
* Fix Cache errors, fallback to transport payment intent in db
* Optional status after decline, clean cache service

= 1.1.5 =
* Release Date - 16 March 2022*

* add session logging
* make security headers case insensitive
* fix style issue for legacy themes
* dynamic card logos
* add SVG logo max height
* embedded fields only for WooC 4.8+
* support for https://www.skyverge.com/product/woocommerce-sequential-order-numbers/
* make asyncIntent request unique, additional logging, webhook logging icons

= 1.1.4 =
* Release Date - 21 February 2022*

* add token caching + improve logging

= 1.1.3 =
* Release Date - 28 January 2022*

* add support for non-permalink setups
* bugfix - pay old orders

= 1.1.2 =
* Release Date - 13 January 2022*

* Bugfix weChat environment setting

= 1.1.1 =
* Release Date - 07 January 2022*

* Limit descriptor string length, enhanced error handling (browser console)

= 1.1.0 =
* Release Date - 21 December 2021*

* Updated Card Logos

= 1.0.5 =
* Release Date - 12 December 2021*

* extended logging frontend, remove JS check for complete input
* extended logging for webhooks
* more robust JS on separate checkout pages
* Upgrade JS lib

= 1.0.4 =
* Release Date - Nov 2021*

* Supporting wooCommerce subscriptions
* Payment method icons for cards
* Upgrade JS lib
* Renaming Client ID label

= 1.0.3 =
*Release Date - 06 August 2021*

* Bug fixing limited character length

= 1.0.2 =
*Release Date - 19 April 2021*

* Improved CSS for better checkout experience

= 1.0.1 =
*Release Date - 13 April 2021*

* Refactored JS
* Replacing of curl with wp-core
* Compatibility with checkoutWC plugin

= 1.0.0 =
*Release Date - 19 Marc 2021*

* Initial version
