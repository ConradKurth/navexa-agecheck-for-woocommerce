=== Navexa AgeCheck for WooCommerce ===
Contributors: navexa
Tags: age verification, age check, woocommerce, checkout
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Age verification for age-restricted WooCommerce orders shipping to US addresses, powered by Navexa AgeCheck.

== Description ==

Navexa AgeCheck checks a shopper's age before an age-restricted order ships. It verifies orders shipping to US addresses only.

This is a development build. Checkout enforcement, order holds and the shopper's Verify button are not included yet.

Pricing: $0.49 per billable pass. There is no monthly fee.

== External services ==

This plugin connects to the Navexa AgeCheck API (https://verify.navexa.co), operated by Navexa. It is needed to check shoppers' ages.

What is sent, and when, in this build:

* When you open the AgeCheck settings tab, and about once an hour while the plugin is in use: your API keys (as request credentials) and your store's address (scheme, host and port), so AgeCheck can report whether each key is live or test, the key's permissions, whether your store's address is allowed, your minimum age and your store's display name.

Navexa terms of service and privacy policy: https://navexa.co

The plugin sends no telemetry.

== Changelog ==

= 0.1.0 =
* Scaffold: settings tab, API client, live/test mode detection, copied-site detection.
