=== Navexa AgeCheck for WooCommerce ===
Contributors: navexa
Tags: age verification, age check, woocommerce, checkout, id verification
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Age verification for age-restricted WooCommerce orders shipping to US addresses, powered by Navexa AgeCheck.

== Description ==

Navexa AgeCheck checks a shopper's age before an age-restricted order ships. It verifies orders shipping to US addresses only.

Shoppers verify on AgeCheck's own page (verify.navexa.co) with an ID document and a selfie. When your Navexa account runs the data check first and the order ships to the shopper's US billing address, AgeCheck can instead confirm the shopper's age from their billing name and address and the date of birth they type on its page, against US data sources; any other shopper takes the ID check. Your store never sees their date of birth or ID images. You choose when they verify: at checkout, before they pay (the default), or after payment, while the order is held.

How orders are handled:

* In "Before payment" mode (the default), a shopper with age-restricted items in the cart selects Verify age at checkout and verifies on AgeCheck's page; the order cannot be placed until they pass. This works on the classic checkout and the block checkout. The check is tied to the shipping address: changing it afterwards needs a new check.
* A paid order with age-restricted products moves to "Awaiting age check" instead of Processing, and stays there until AgeCheck clears it. For a check passed at checkout that is immediate. Stock is reserved and you get the usual new-order email; the shopper gets their processing email once the order is released.
* Moving such an order to Processing or Completed by hand, or by another plugin, is refused with an order note. The "AgeCheck: release without age check" order action releases it anyway; it records who did it and why.
* Which products need an age check is set in WooCommerce > Settings > AgeCheck (every product, chosen categories or tags, or all but those) and per product in the product editor. Orders whose customer has an exempt role skip the check.
* Local pickup orders are never checked: check the buyer's ID at the counter.
* Orders paid on the "pay for order" page, and every order in "After payment" mode, are accepted, then held until the shopper verifies. AgeCheck emails the shopper a link to its page (good for 7 days; the ID must match the order's billing name), and the order-received page and the order in My account show a "Verify now" button. The AgeCheck panel on the order says whether the email went out; Order actions can resend it (at most 5 a day) or withdraw it. When the order is cancelled, refunded in full, marked failed, trashed or deleted, the plugin has AgeCheck withdraw the link by itself, in the background, and notes the result on the order; a partial refund leaves it working. While AgeCheck is not sending verification emails yet, or until you set your store name in the AgeCheck dashboard, the order stays held and a notice tells you so.
* A signed-in customer who passed an age check at your store recently may not have to verify again when AgeCheck's returning-customer check is on for your account; the order panel then says "Returning customer (reused verification)". The plugin sends AgeCheck only a keyed reference to the customer's account, never their name or email address for this.
* If the shopper fails, the order is never released. By default it is refunded through the payment gateway when the gateway can refund by API; otherwise it is listed under "AgeCheck: denied, refund required" on the Orders screen.
* If AgeCheck cannot be reached, the plugin fails closed: no age-restricted order skips its check because AgeCheck did not answer. In "Before payment" mode, Verify age says "Age verification is temporarily unavailable" and a new order cannot be placed until AgeCheck answers again. Every paid age-restricted order, including one that passed at checkout, stays in "Awaiting age check" until AgeCheck clears it (see the FAQ).
* The shipping address of an order linked to an age check cannot be changed.
* AgeCheck verifies US addresses only, including Puerto Rico, Guam, the US Virgin Islands, American Samoa, the Northern Mariana Islands, the US Minor Outlying Islands and APO/FPO addresses.

Express payment buttons:

Apple Pay, Google Pay, Link, Amazon Pay, WooPay and PayPal buttons on product, cart and checkout pages let a shopper pay without the checkout form, so without the Verify age button. By default the plugin hides them on age-restricted products and carts through each payment plugin's own filters (WooPayments, WooCommerce Stripe Gateway and WooCommerce PayPal Payments); the shopper then pays through the checkout form. An express order that still gets through is refused before payment in "Before payment" mode, and in every mode a paid age-restricted order is held until AgeCheck clears it. The setting is under WooCommerce > Settings > AgeCheck > Checkout protection.

Accessibility:

The Verify age button is a standard button that works with the keyboard and stays focusable while a check runs. Progress and results are announced to screen readers through a polite live region, and when a check you started ends, the focus returns to the button or, once you have passed, to the "Age verified" status. The button uses your theme's button styles, so its colour contrast is your theme's.

Optional bot protection:

Turn on Cloudflare Turnstile under Checkout protection to require a Turnstile check before a shopper's age check starts. It is off by default; when it is on and Turnstile cannot confirm the shopper, the age check does not start.

Optional entry age gate (self-attestation, not age verification):

Turn on "Entry age gate" in WooCommerce > Settings > AgeCheck to ask visitors to confirm their age ("I am 21 or older" or "I am under 21") when they enter the store, on every page or only on age-restricted product, category and tag pages. It is off by default. It is self-attestation only: it asks, and anyone can answer yes, so it does not verify anyone's age and does not replace the age check at checkout, which still applies to every age-restricted order. The age shown is the minimum age set in the Navexa dashboard (21 until the plugin has connected), and the store name is the display name set there, or your site title. The answer is remembered in the visitor's own browser for the number of days you choose (30 by default; 0 remembers it only in the browser tab where they answered, so a new tab asks again); nothing is sent to your server, to Navexa or to anyone else, no API key is needed, and the decision is made in the browser, so it works with page caching. Visitors who say they are under age go to an address you choose, or stay on the gate with a short message. The gate is an accessible dialog: keyboard focus stays in it, Escape does not dismiss it, and the rest of the page is hidden from screen readers while it is open. It never shows in wp-admin, on the login page, to the REST API or on AgeCheck's return page.

Privacy tools:

The plugin adds a suggested section to your privacy policy (Settings > Privacy > Policy Guide) and takes part in WordPress's personal data export and erasure tools for the age check details it stores with orders.

Known limits:

* Verification emails depend on AgeCheck sending them (AgeCheck turns them on for every store at once) and on your store name being set in the AgeCheck dashboard; until then shoppers of held orders verify from the "Verify now" button on their order page, or you release the order after checking their age yourself.
* WooCommerce Subscriptions renewals that need an age check are held for you to review and release.
* A plugin that ships or fulfils an order as soon as payment completes (on the woocommerce_payment_complete action) rather than on the order status cannot be stopped. AgeCheck is told about every completed order (ReportShipment) and flags any that shipped without a clearance.
* Express buttons from payment plugins other than WooPayments, WooCommerce Stripe Gateway and WooCommerce PayPal Payments are not hidden (see the FAQ).
* Held orders are checked every 15 minutes through Action Scheduler, which depends on WP-Cron. Set up a real system cron if your site has little traffic, and add the webhook in the Navexa dashboard so orders are released the moment the shopper passes.

Pricing: $0.49 per billable pass, $0.10 for a returning customer's reused pass. There is no monthly fee.

Navexa AgeCheck lowers the risk of selling to someone under age; it does not remove it, and using it does not by itself make your store compliant with any law.

== Installation ==

1. Install and activate WooCommerce 9.9 or later.
2. Install and activate Navexa AgeCheck for WooCommerce.
3. In the Navexa dashboard (https://tax.navexa.co), create two API keys: a start key with the agecheck:verify scope, and a fulfilment key with the agecheck:verify and agecheck:clearance scopes. Add your store's address (for example https://shop.example) to the allowed origins.
4. In WooCommerce > Settings > AgeCheck, paste both keys, choose when shoppers verify, and choose which products need an age check. The Connection lines show whether each key works, whether it is live or a test key, and what is missing.
5. Recommended: add a webhook endpoint in the Navexa dashboard with the URL shown on the settings tab, and paste its signing secret, so held orders are released the moment the shopper passes.
6. Recommended: set up a real system cron. Held orders are also checked every 15 minutes through Action Scheduler, which depends on WP-Cron.

== Frequently Asked Questions ==

= Which addresses can AgeCheck verify? =

US addresses only, including US territories and APO/FPO addresses. An age-restricted order shipping anywhere else is refused at checkout, or held if it was placed another way.

= Is the entry age gate an age verification? =

No. It is self-attestation: visitors click "I am 21 or older" and anyone can do that. It is there for stores that want a front-door age prompt. Age-restricted orders are still verified by AgeCheck at checkout (or held until they are), whatever the visitor answered at the gate.

= The entry age gate shows late, or only after a click, with a speed plugin =

The gate's script has to run as the page loads, in the page head. Its tags ask Cloudflare Rocket Loader, LiteSpeed Cache and WP Rocket to leave them alone, but if your optimization plugin or service defers or delays JavaScript anyway, exclude `entry-gate.min.js` and `navexaAgeCheckGate` from its "delay JavaScript" and "defer JavaScript" settings.

= Does my store see the shopper's date of birth or ID? =

No. Shoppers type their date of birth and photograph their ID only on AgeCheck's own page, verify.navexa.co. Your store keeps a verification reference, the result and a one-way hash of the checked shipping address with the order.

= What happens to Apple Pay, Google Pay and PayPal buttons? =

With "Hide express checkout buttons on age-restricted carts and products" on (the default), on age-restricted products and carts:

* WooPayments: Apple Pay, Google Pay and Amazon Pay (product, cart and checkout pages, classic and block) and WooPay are hidden. Link is part of the card form on the checkout page, so the shopper verifies before paying.
* WooCommerce Stripe Gateway: Apple Pay, Google Pay, Link and Amazon Pay are hidden on product, cart and checkout pages (classic and block).
* WooCommerce PayPal Payments (its default SDK v6 buttons and the older SDK v5 ones): the PayPal, Pay Later and Venmo buttons on product, cart and mini-cart are not rendered, and the express buttons on the block cart and checkout are removed (PayPal then stays on the block checkout only in its "Place order" mode). With SDK v6 this also covers PayPal's Apple Pay and Google Pay buttons; with SDK v5, whether they follow is not confirmed. PayPal on the classic checkout page stays: it is the payment method there, and WooCommerce checks the checkout form, including the age check, before the order is placed.
* Buttons on the "pay for order" page are never hidden: those orders are accepted and held until the shopper verifies.

If an express order still gets through (another payment plugin, a setting turned off, or a cart that changed after the page loaded): when it goes through WooCommerce's checkout in "Before payment" mode it is refused before payment with "Verify your age on the checkout page"; and whichever way it is placed, a paid age-restricted order is held as "Awaiting age check" until AgeCheck clears it. Some express flows create the order without WooCommerce's checkout (for example PayPal's "Pay now" experience); those are held after payment.

= What if a shopper fails the age check? =

The order is never released. Depending on your setting, it is refunded through the payment gateway (when the gateway can refund by API) or listed under "AgeCheck: denied, refund required" for you to refund.

= What happens when AgeCheck can't be reached? =

The plugin fails closed: no age-restricted order skips its check because AgeCheck did not answer.

* In "Before payment" mode, Verify age says "Age verification is temporarily unavailable" and a new order cannot be placed until AgeCheck answers again. Place order asks AgeCheck again even for a shopper who passed earlier, and is refused with the same message; a shopper who has not verified yet is asked to verify first.
* Every paid age-restricted order already placed stays in "Awaiting age check": one that passed at checkout (normally released at once), orders accepted in "After payment" mode or on the "pay for order" page, and express orders placed without the checkout. The plugin keeps asking AgeCheck about them, with retries after a failed attempt and a check of held orders every 15 minutes (100 orders at a time), and releases each one once AgeCheck clears it. If AgeCheck answers with an error instead of not answering, the order gets a note and the check of held orders asks about it again after 6 hours. Meanwhile, a shopper who selects "Verify now" on their order page is told "Age verification is temporarily unavailable".
* You can still release an order yourself with "AgeCheck: release without age check".

There is no setting that lets orders through during an outage.

= Can I release a held order myself? =

Yes, with the "AgeCheck: release without age check" order action. It records who released it, when and why.

= Does it work with the block checkout and HPOS? =

Yes. It supports the classic and block checkout and both order storage modes (High-Performance Order Storage and posts).

= How do I test it? =

Use test keys from the Navexa dashboard. AgeCheck then runs a simulator instead of a real check, a TEST MODE banner shows, and held orders are never released automatically.

= What is removed when I delete the plugin? =

Its settings, keys, scheduled jobs, rate-limit table and cached data. Each order's age check details and each product's age check setting are kept as an audit trail unless you turn on "Delete data on uninstall". Orders still awaiting an age check are moved to On hold with a note; they are never released.

== Screenshots ==

1. WooCommerce > Settings > AgeCheck: API keys, checkout mode and connection status.
2. The Verify age button on the block checkout.
3. The Verify age button on the classic checkout.
4. The AgeCheck panel on an order: the check's result, why the order is held, the verification, the emailed link and what to do next.

== External services ==

This plugin connects to the Navexa AgeCheck API (https://verify.navexa.co), operated by Navexa. It is needed to check shoppers' ages.

What is sent, and when:

* When you open the AgeCheck settings tab, and about once an hour while the plugin is in use: your API keys (as request credentials) and your store's address (scheme, host and port), so AgeCheck can report whether each key is live or test, the key's permissions, whether your store's address is allowed, your minimum age and your store's display name.
* When a shopper selects Verify age at checkout: the shipping address of the order they are about to place, the address of your store's return page, and, when the shopper has filled in a billing first and last name and a US billing address and the order ships to that billing address, that billing name and billing address. AgeCheck uses them for the age check: it can check them, with the date of birth the shopper types on its page, against US data sources instead of asking for an ID. Otherwise (for example when the order ships somewhere else) no name or billing address is sent, and the shopper takes the ID check. No email address. Never a date of birth: the shopper types that only on AgeCheck's own page, verify.navexa.co, which the plugin opens in a popup window (or the same tab when popups are blocked).
* While the AgeCheck window is open: the shopper's checkout page asks AgeCheck (verify.navexa.co) every few seconds whether the check is finished. It sends only that check's session token, in a request header; no personal data.
* When the shopper comes back, and when they place the order: the verification's id, to read whether it passed.
* When an age-restricted order is placed after a check at checkout, or is held: an order identifier (a random id for this store, the site id and the order id) and its shipping address (its billing address when the order has no shipping address), and, when no check was done at checkout, the billing name, billing email and billing address so AgeCheck can check the shopper's ID against that name, and the address of the order's order-received page (it includes the order number and the order key), which AgeCheck's page links back to. A date of birth is never sent; shoppers type it only on verify.navexa.co.
* When a held order's shopper is to be emailed a verification link (and when you resend or withdraw it from Order actions): the verification's id and the address of the store's return step for that order (the order id and a keyed check value, not the order key). AgeCheck sends the email to the billing email it already has.
* When such an order is cancelled, refunded in full, marked failed, trashed or deleted: the verification's id, to withdraw its emailed links.
* When a shopper selects Verify now on their order page: the verification's id and the same return address, to get a fresh 30-minute session on AgeCheck's page.
* For a signed-in customer (at Verify age, and for an order placed with an account): a keyed reference to the account (a one-way value derived from the user id and a secret kept on your site; not the user id, name or email address), so AgeCheck can recognise a returning customer at your store when its returning-customer check is on.
* While an order is held: the order id and the same shipping address again, to ask whether it may ship.
* When an age-restricted order is completed: the order id and the completion time (ReportShipment).
* AgeCheck sends this store webhooks (verification passed, failed, or waiting too long) carrying only ids; they are checked against your webhook secret.

Navexa Terms of Service (section 16 covers Navexa AgeCheck): https://navexa.co/terms#agecheck
Navexa Privacy Policy: https://navexa.co/privacy

Navexa uses these providers for AgeCheck. The plugin itself never contacts them; only AgeCheck does:

* Didit (https://didit.me) checks the shopper's ID document, selfie and liveness on verify.navexa.co, AgeCheck's own page, and may check the shopper's details against US data sources (financial and credit-bureau records). Didit verification privacy notice: https://didit.me/terms/verification-privacy-notice. Didit end-user terms: https://didit.me/terms/identity-verification
* Resend delivers verification-link emails. It receives the shopper's email address and your store's name, only when AgeCheck emails a shopper a link for a held order (once AgeCheck turns verification emails on). Resend privacy policy: https://resend.com/legal/privacy-policy. Resend terms of service: https://resend.com/legal/terms-of-service

Cloudflare Turnstile (optional, off by default): when you turn it on under Checkout protection, the checkout page loads Cloudflare's Turnstile script from https://challenges.cloudflare.com, which collects information about the shopper's browser to tell people from bots, and when the shopper selects Verify age the plugin sends Cloudflare's siteverify API (https://challenges.cloudflare.com/turnstile/v0/siteverify) your Turnstile secret key, the shopper's Turnstile token and the shopper's IP address. Nothing is sent to Cloudflare while Turnstile is off. Cloudflare Turnstile privacy addendum: https://www.cloudflare.com/turnstile-privacy-policy/. Cloudflare privacy policy: https://www.cloudflare.com/privacypolicy/. Cloudflare website and online services terms: https://www.cloudflare.com/website-terms/

Apart from Cloudflare's Turnstile script when you turn Turnstile on, the plugin's scripts are bundled with the plugin; nothing is loaded from another host.

The optional entry age gate contacts no service at all: it needs no API key, sends nothing anywhere, and keeps the visitor's answer only in their own browser (local storage, or for 0 days session storage, which lasts only as long as that browser tab).

== Source code and build ==

The minified scripts in assets/js/ and assets/build/ are built from the sources in assets/src/ (the loader, the classic checkout and Turnstile glue and the entry age gate in assets/src/loader/, the checkout block in assets/src/blocks/). The full source, including the build configuration, is at https://github.com/ConradKurth/navexa-agecheck-for-woocommerce. To rebuild: `npm ci && npm run build` (Node 24).

Translations: the template is languages/navexa-agecheck-for-woocommerce.pot.

The plugin sends no telemetry.

== Changelog ==

= 1.0.4 =
* Fix: after a declined payment, checkout no longer asks the shopper to verify their age again. The order is already tied to their age check, so retrying the payment (for example with another card) places it without a second check, which AgeCheck would have billed. Changing the shipping address or the cart after a declined payment still needs a new age check.

= 1.0.3 =
* Admin notices show only on WooCommerce screens (never the WordPress dashboard or other plugins' pages), and each one can be dismissed until its reason changes. The WooCommerce-missing notice shows on the Plugins screen only.

= 1.0.2 =
* Block checkout: the Place order error gives the reason (for example, US addresses only) instead of a generic prompt.
* The order-received and My account order pages say when an order will not ship after a failed age check, and what happens to the payment.
* The checkout tells shoppers who closed the age check window to select Verify age again.

= 1.0.1 =
* The plugin's home page is the Navexa AgeCheck page (https://navexa.co/agecheck).

= 1.0.0 =
* Scaffold: settings tab, API client, live/test mode detection, copied-site detection.
* Order holds: "Awaiting age check" status, release when AgeCheck clears the order, webhook receiver, 15-minute check of held orders, refund handling for failed checks, local pickup exemption, product and category rules, order panel and Orders filters.
* Pre-payment age check: Verify age button on classic and block checkout, checkout enforcement, US-only ship-to (US territories and APO/FPO included), rate limits, return page.
* Express payment buttons (WooPayments, WooCommerce Stripe Gateway, WooCommerce PayPal Payments) hidden on age-restricted products and carts, on by default.
* Optional Cloudflare Turnstile check before an age check starts, off by default.
* Data check first (where the Navexa account runs it): when the order ships to the shopper's US billing address, the Verify age start sends the billing name and that address, normalized like the ship-to, so the shopper can pass without an ID; a shopper shipping elsewhere sends neither and takes the ID check. On the classic checkout, the billing name the shopper types is kept in their session for this, as the block checkout does.
* Optional entry age gate, off by default: a self-attestation prompt (not age verification) on every page or only age-restricted pages, remembered in the visitor's browser, accessible, no network requests, page-cache safe.
* Privacy policy suggestion, personal data exporter and eraser.
* Translation template; accessible Verify age button (keyboard, focus return, screen reader announcements).
* Uninstall removes all plugin settings and data, keeps order audit data unless "Delete data on uninstall" is on, and moves orders awaiting an age check to On hold.
* Verification emails for held orders (pay for order, "After payment" mode): AgeCheck emails the shopper a 7-day link; resend and withdraw from Order actions; the order panel shows the email's state; a notice says when AgeCheck is not sending the emails yet or the store name is missing. The NAVEXA_AGECHECK_EMAIL_LINKS constant set to false turns them off on a site.
* "Verify now" on the order-received page and My account's order page for a held order.
* Returning customers: a keyed account reference on every signed-in customer's age check, for AgeCheck's returning-customer check (reused passes clear like any pass).
* Test keys: held orders show the shopper's latest check result and a note when it passes or fails (test keys never release or refund orders).
* Verification links of orders that will not ship (cancelled, refunded in full, failed, trashed or deleted) are withdrawn automatically in the background, with the result noted on the order (in the WooCommerce log for a deleted one).

== Upgrade Notice ==

= 1.0.4 =
Fixes a second age check (billed by AgeCheck) after a declined payment. Recommended for every store.

= 1.0.3 =
Admin notices limited to WooCommerce screens and dismissible.

= 1.0.2 =
Clearer shopper messages after a failed or interrupted age check.

= 1.0.1 =
Plugin home page link only.

= 1.0.0 =
First release.
