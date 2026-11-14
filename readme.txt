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

This is a development build. Shoppers can verify their age at checkout (classic and block checkout) before they pay; order holds and release after payment are not included yet.

Local pickup orders skip the age check: check ID at the counter.

Pricing: $0.49 per billable pass. There is no monthly fee.

== External services ==

This plugin connects to the Navexa AgeCheck API (https://verify.navexa.co), operated by Navexa. It is needed to check shoppers' ages.

What is sent, and when, in this build:

* When you open the AgeCheck settings tab, and about once an hour while the plugin is in use: your API keys (as request credentials) and your store's address (scheme, host and port), so AgeCheck can report whether each key is live or test, the key's permissions, whether your store's address is allowed, your minimum age and your store's display name.
* When a shopper selects Verify age at checkout: the shipping address of the order they are about to place, the billing name, billing address and email address your store holds for them (whichever are filled in), and the address of your store's return page. Never a date of birth: the shopper types that only on AgeCheck's own page, verify.navexa.co, which the plugin opens in a popup window (or the same tab when popups are blocked).
* While the AgeCheck window is open: the shopper's checkout page asks AgeCheck (verify.navexa.co) every few seconds whether the check is finished. It sends only that check's session token, in a request header; no personal data.
* When the shopper comes back, and when they place the order: the verification's id, to read whether it passed.

On AgeCheck's page the shopper may be asked to photograph an ID and take a selfie. That step is run by Didit (https://didit.me), AgeCheck's identity verification provider, under AgeCheck's terms. The plugin itself never contacts Didit: only AgeCheck's page does.

The plugin's checkout script is bundled with the plugin; nothing is loaded from another host.

Navexa terms of service: https://navexa.co/terms
Navexa privacy policy: https://navexa.co/privacy

== Source code and build ==

The minified scripts in assets/js/ and assets/build/ are built from the sources in assets/src/. The full source, including the build configuration, is at https://github.com/ConradKurth/navexa-agecheck-for-woocommerce. To rebuild: `npm ci && npm run build` (Node 24).

The plugin sends no telemetry.

== Changelog ==

= 0.1.0 =
* Scaffold: settings tab, API client, live/test mode detection, copied-site detection.
* Pre-payment age check: Verify age button on classic and block checkout, checkout enforcement, US-only ship-to (US territories and APO/FPO included), rate limits, return page.
