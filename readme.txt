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

This is a development build. Order holds are included; the shopper's Verify button at checkout and verification emails are not included yet.

How orders are handled:

* A paid order with age-restricted products moves to "Awaiting age check" instead of Processing, and stays there until AgeCheck clears it. Stock is reserved and you get the usual new-order email; the shopper gets their processing email once the order is released.
* Moving such an order to Processing or Completed by hand, or by another plugin, is refused with an order note. The "AgeCheck: release without age check" order action releases it anyway; it records who did it and why.
* Which products need an age check is set in WooCommerce > Settings > AgeCheck (every product, chosen categories or tags, or all but those) and per product in the product editor. Orders whose customer has an exempt role skip the check.
* Local pickup orders are never checked: check the buyer's ID at the counter.
* Orders paid on the "pay for order" page are accepted, then held until the shopper verifies.
* If the shopper fails, the order is never released. By default it is refunded through the payment gateway when the gateway can refund by API; otherwise it is listed under "AgeCheck: denied, refund required" on the Orders screen.
* The shipping address of an order linked to an age check cannot be changed.
* AgeCheck verifies US addresses only, including Puerto Rico, Guam, the US Virgin Islands, American Samoa, the Northern Mariana Islands and the US Minor Outlying Islands.

Known limits:

* Verification emails to shoppers are not available yet, so an order held after payment waits until you release it yourself or the shopper completes their check another way.
* WooCommerce Subscriptions renewals that need an age check are held for you to review and release; AgeCheck cannot reuse the first check yet.
* A plugin that ships or fulfils an order as soon as payment completes (on the woocommerce_payment_complete action) rather than on the order status cannot be stopped. AgeCheck is told about every completed order (ReportShipment) and flags any that shipped without a clearance.
* Held orders are checked every 15 minutes through Action Scheduler, which depends on WP-Cron. Set up a real system cron if your site has little traffic, and add the webhook in the Navexa dashboard so orders are released the moment the shopper passes.

Pricing: $0.49 per billable pass. There is no monthly fee.

== External services ==

This plugin connects to the Navexa AgeCheck API (https://verify.navexa.co), operated by Navexa. It is needed to check shoppers' ages.

What is sent, and when, in this build:

* When you open the AgeCheck settings tab, and about once an hour while the plugin is in use: your API keys (as request credentials) and your store's address (scheme, host and port), so AgeCheck can report whether each key is live or test, the key's permissions, whether your store's address is allowed, your minimum age and your store's display name.
* When an age-restricted order is held, or was verified at checkout: an order identifier (a random id for this store, the site id and the order id) and its shipping address, and, when no check was done at checkout, the billing name, billing email and billing address so AgeCheck can check the shopper's ID against that name. A date of birth is never sent; shoppers type it only on verify.navexa.co.
* While an order is held: the order id and shipping address again, to ask whether it may ship.
* When an age-restricted order is completed: the order id and the completion time (ReportShipment).
* AgeCheck sends this store webhooks (verification passed, failed, or waiting too long) carrying only ids; they are checked against your webhook secret.

Navexa terms of service: https://navexa.co/terms
Navexa privacy policy: https://navexa.co/privacy

Navexa uses these providers for AgeCheck. The plugin does not connect to them; Navexa does:

* Didit checks the shopper's ID document, selfie and liveness on verify.navexa.co, and may check the shopper's details against US data sources (financial and credit-bureau records). Didit verification privacy notice: https://didit.me/terms/verification-privacy-notice. Didit end-user terms: https://didit.me/terms/identity-verification
* Resend delivers verification-link emails. It receives the shopper's email address and your store's name, and only when verification emails are turned on (they are not available in this build). Resend privacy policy: https://resend.com/legal/privacy-policy. Resend terms of service: https://resend.com/legal/terms-of-service

The plugin sends no telemetry.

== Changelog ==

= 0.1.0 =
* Scaffold: settings tab, API client, live/test mode detection, copied-site detection.
* Order holds: "Awaiting age check" status, release when AgeCheck clears the order, webhook receiver, 15-minute check of held orders, refund handling for failed checks, local pickup exemption, product and category rules, order panel and Orders filters.
