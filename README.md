# Navexa AgeCheck for WooCommerce

WordPress plugin that gates age-restricted WooCommerce orders on a Navexa AgeCheck pass. US ship-to addresses only. GPL-2.0-or-later. The wordpress.org readme is `readme.txt`.

Status: **W0 scaffold + W1 hold core + W2 pre-payment**, merged; **W4 hardening** (express buttons, i18n, privacy, readme, uninstall, button accessibility, optional Turnstile) on top; **W3a**: the data-match identity on the pre-payment start (engine E5 `data_then_doc`, tax v0.0.167) and the entry age gate (W3 PR 13, self-attestation). Paid age-restricted orders are held in "Awaiting age check" until AgeCheck clears them (or staff use the logged override): binding, release, the webhook receiver, the sweep, ReportShipment, the ship-to guard, denial handling, the order panel and Orders filters (W1). In pre-payment mode the shopper verifies before placing the order, on classic and block checkout: the checkout guard, rate limits, the Store API start/refresh extension, the return page, the bundled loader and the checkout block (W2); the order is then bound to that verification and released by the same hold path. **W3b** (W3 PRs 12 and 14): emailed verification links for held orders (`SendVerificationLink`, `RevokeVerificationLinks`, engine E8c), "Verify now" on the shopper's order pages, and `customer_ref` for per-store reuse (E12a, `PASS_CLASS_REUSED`). **W5**: an order that will not ship (cancelled, refunded in full, failed, trashed or deleted) has its emailed links withdrawn (`RevokeVerificationLinks`, asynchronously), and the release tooling: a reproducible zip, its wp-env install check and a tag-triggered draft GitHub Release (see "Releasing"). The engine contract is E3, E9 (hosted status CORS, tax v0.0.163), E4a and E10a (tax v0.0.164), E5, E8c and E12a (tax d6dead63); see `proto/agecheck/v1/verification.proto` in the tax repo. AgeCheck's `AGECHECK_LINKS` and `AGECHECK_REUSE` are off in production: the plugin keeps held orders held and tells the merchant why. The plan is `design-final.md` §5 in the AgeCheck planning cache; owner decisions that override it are in `owner-decisions.md` there.

## Layout

| Path | What |
|---|---|
| `navexa-agecheck-for-woocommerce.php` | Headers, constants, PSR-4 autoloader (`Navexa\AgeCheck\X` → `includes/X.php`) |
| `includes/Plugin.php` | Hook wiring; HPOS and block-checkout compatibility declarations |
| `includes/Install.php` | install_id, guard secret, activated_at, site address fingerprint (the raw `home` option); clone pause and "Confirm this site" |
| `includes/Options.php` | Option names and getters (keys, mode); safe to load outside the settings screen |
| `includes/Settings.php` | WooCommerce > Settings > AgeCheck (`WC_Settings_Page`; loaded only from `woocommerce_get_settings_pages`) |
| `includes/Client.php`, `ConnectError.php` | Connect unary JSON over `wp_remote_post`; 8 s timeout, one attempt, fail closed |
| `includes/Mode.php`, `IntegrationInfo.php` | `GetIntegrationInfo` cached 1 h (failures 60 s), last livemode per key kept for the TEST MODE banner; live/test gate rules |
| `includes/Meta.php` | Every order meta key (`_navexa_agecheck_*`) and the TEST note prefix |
| `includes/Requirement.php` | Which orders and carts need a check: store rule (all / include / exclude categories and tags), per-product setting, exempt customer roles, local pickup skipped (the one pickup list, `pickup_method_ids()`), orders from before activation skipped; sticky once required |
| `includes/Hold.php` | `wc-agecheck-hold` status; `payment_complete` interception; the pre-save backstop; refused exits; release to the intercepted status; the logged override |
| `includes/AddressGuard.php` | Reverts ship-to edits on bound orders |
| `includes/OrderAddress.php` | The plugin's only address normalizer (`normalize()`: NFC, Go whitespace, UTF-8 and length checks, ZIP+4 → ZIP, territory region); which address an order ships to; US + territories check; the bound ship-to hash |
| `includes/Binding.php` | External ids; re-entrant `AttachOrder` of the checkout verification; order-bound `CreateVerification` (with `customer_ref` for an account's order); the verification's status and pass class on the order |
| `includes/Links.php` | Emailed verification links for held orders (W3 PR 12): when to ask, the persisted idempotency key, every answer recorded on the order, the store-wide refusal (links off, no display name), staff resend and withdraw |
| `includes/LinkRevoke.php` | W5: withdraws the emailed links of an order that will not ship (cancelled, refunded in full, failed, trashed, deleted) through Action Scheduler: persisted idempotency key, the order's lock, retries, the sweep's re-queue of a withdrawal left pending, the answer in order meta and a note |
| `includes/LinkSender.php`, `EngineLinkSender.php`, `DisabledLinkSender.php`, `LinkResult.php` | `SendVerificationLink` (E8c) and how its answers and refusals read; the `NAVEXA_AGECHECK_EMAIL_LINKS` kill switch |
| `includes/OrderVerify.php` | "Verify now" on the order-received and view-order pages of a held order; `?wc-api=navexa_agecheck_order` start step (order key + session-bound nonce → `CreateHostedSession`) and return step (keyed tag → release run) |
| `includes/CustomerRef.php` | `customer_ref` (W3 PR 14): `wpc1_` + HMAC of the blog and user id under a key from the guard secret; guests none |
| `includes/Release.php` | The release run: per-order lock, persisted `GetClearance` key, replayed handling, livemode gate, retries |
| `includes/Denial.php` | Failed checks: gateway refund or the manual refund list |
| `includes/Lock.php` | Atomic option-row locks and claim-once markers |
| `includes/OrderQuery.php` | `wc_get_orders()` meta queries on HPOS and on the posts table (which drops `meta_query`) |
| `includes/Webhook.php` | `POST /wp-json/navexa-agecheck/v1/webhook`, `X-Webhook-Signature-V2`, event-id dedupe |
| `includes/Sweep.php`, `Shipment.php` | 15-minute sweep; ReportShipment on completed |
| `includes/Subscriptions.php` | Renewals held for review (spike notes in the class docblock) |
| `includes/Checkout/OrderPay.php` | Pay-for-order: accept and hold; Store API ship-to change refused on bound orders (cart checkouts are `Blocks`') |
| `includes/Admin/` | Order panel and actions, Orders filters, notices, product field |
| `includes/ShipTo.php` | A normalized ship-to (from `OrderAddress::normalize()`), the port of the engine's `ShipToCanonical` (pinned by engine vectors), the guard MAC under the install's key |
| `includes/GuardToken.php` | HS256 JWT port of Zavro's scoped token; the checkout guard `{scope, sid, vid, shipto_mac, iat, exp}` |
| `includes/RateLimit.php` | Atomic fixed-window counters in `{prefix}navexa_agecheck_rate_limits` (HMAC keys, never an IP); client IP with an optional trusted proxy header; proxy warning from recent starts (one IP, private peer addresses or unread proxy headers) |
| `includes/Start.php` | Store API extension `navexa-agecheck`: start/refresh update callback and cart endpoint data |
| `includes/ReturnPage.php` | `?wc-api=navexa_agecheck_return`: BroadcastChannel + storage nudge, `window.close()`, link back to checkout |
| `includes/Frontend.php` | Registers the bundled loader, the classic glue and the checkout block scripts |
| `includes/Checkout/Gate.php` | The pre-payment admission rules both checkouts share |
| `includes/Checkout/Session.php` | The shopper's sid, counter, verification record and guard in the WooCommerce session |
| `includes/Checkout/Classic.php` | `woocommerce_after_checkout_validation` + the Verify age button above Place order |
| `includes/Checkout/Blocks.php`, `BlocksIntegration.php` | `woocommerce_checkout_validate_order_before_payment` (WC 9.9+) and the checkout block integration |
| `includes/Express.php` | Hides express payment buttons on restricted products and carts through each gateway's own filters (W4; matrix below) |
| `includes/Turnstile.php` | Optional Cloudflare Turnstile on the start (off by default; siteverify; fail closed) |
| `includes/Privacy.php` | Privacy policy suggestion; personal data exporter and eraser for the plugin's order meta |
| `includes/EntryGate.php` | The optional entry age gate (self-attestation, not verification): which pages carry it, its head script and configuration (no key, no PII, no network call) |
| `includes/Uninstall.php` | What `uninstall.php` removes (self-contained: the plugin is not loaded at uninstall) |
| `languages/` | `navexa-agecheck-for-woocommerce.pot` (wp-cli `i18n make-pot`; PHP, the built JS and block.json) |
| `assets/src/loader/` | The loader (TypeScript, zero dependencies), the classic glue, the Turnstile glue (`turnstile.ts`, loaded only with Turnstile on), the button's accessibility helpers (`a11y.ts`) and the entry age gate (`gate.ts` → `entry-gate.js`, loaded only with the gate on); esbuild → `assets/js/` |
| `assets/src/blocks/` | The Verify age checkout inner blocks; wp-scripts → `assets/build/` |
| `tests/phpunit/` | Unit tests (Brain Monkey, no WordPress); `fixtures/ship-to-vectors.json` is exported from the engine |
| `tests/mock-agecheck/` | Zero-dependency Node mock of the engine's Connect API and hosted surface, with fault injection |
| `tests/wp-env/smoke.php` | Smoke checks inside a real WordPress + WooCommerce (wp-env) |
| `tests/wp-env/prepayment.mjs` | The W2 flow over HTTP against wp-env and the mock (setup: `prepayment-setup.php`; `prepayment-orders.php` then follows its orders through the hold and release) |
| `tests/e2e/prepayment-browser.mjs` | The W2 flow in Chromium, WebKit and Firefox (Playwright), and the W4 keyboard and focus checks |
| `tests/wp-env/gate-setup.php`, `tests/e2e/gate-browser.mjs` | The entry gate in real WordPress: which pages carry it, page-cache safety and exclusions over HTTP (`--http-only`, in CI), the optimizer opt-out attributes on both script tags, and the dialog in Chromium (ARIA, focus trap, Escape, remember, 0 days per tab with old localStorage answers ignored, the page's own `aria-hidden` put back, a theme's `html{font-size:62.5%}` and button rules not restyling it, exit page, no third-party request) |
| `tests/wp-env/w3b.php`, `w3b.mjs`, `w3b-setup.php` | W3 PRs 12 and 14 in real WordPress: links on held orders (send, resend through Order actions with a real nonce and capability, the 5-a-day budget, withdraw, links off and no display name recorded once for the store with the notice, the return step, the kill switch), `customer_ref` on order-bound creates and the CLI cart's start, reuse on and off; over HTTP a signed-in shopper's Store API start, reuse at checkout, and "Verify now" from the order-received page through the hosted page and back, and on My account |
| `tests/wp-env/w5.php` | W5 in real WordPress: held orders emailed a link, then cancelled, refunded in part and in full, failed, trashed (`WC_Order::delete()`, and `wp_trash_post()` on the posts table) and deleted for good (straight away, and from the trash before its run); the status change makes no call, Action Scheduler's own runner runs the withdrawal; an outage retried with the same key, a refusal recorded, an order restored before the run, a copied order, a failed order paid again after its link was withdrawn, the sweep re-queuing a withdrawal left pending |
| `bin/build-zip.sh`, `bin/verify-zip.sh` | W5 release tooling: the reproducible distributable zip with SHA256SUMS, and its install, activate and uninstall check in a throwaway wp-env |
| `.github/workflows/release.yml` | On a `v*` tag: version checks, the zip (built twice), the install check on WooCommerce 9.9 and 11.3, and a draft GitHub Release with the zip and SHA256SUMS |
| `tests/wp-env/w4.php`, `w4-uninstall.php` | W4 in real WordPress: express filters (and WooCommerce Stripe Gateway's own helper when it is active), the hold fallback, Turnstile against Cloudflare's test keys, privacy tools, JS translation wiring; then `uninstall.php` with the plugin skipped |

## Development

PHP is not needed on the host; Docker is enough.

```sh
# dev dependencies
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 install
# lint (WordPress Coding Standards + PHPCompatibilityWP 7.4+)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpcs
# unit tests (CI runs PHP 7.4 and 8.3)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit
# static analysis (PHPStan level 6)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpstan analyse --memory-limit=1G
# Node tooling (Node 24 in CI)
npm ci
npm run test:mock      # mock engine
npm run test:loader    # loader and Store API transport (node --test, type stripping)
npm run typecheck      # tsc over assets/src/loader
npm run build          # esbuild loader + wp-scripts block; the output is committed, CI checks it is current
# translation template (after changing any string; I18nTest fails when a PHP string is missing from it)
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app --entrypoint wp wordpress:cli \
  i18n make-pot /app /app/languages/navexa-agecheck-for-woocommerce.pot --slug=navexa-agecheck-for-woocommerce \
  --domain=navexa-agecheck-for-woocommerce --exclude=node_modules,vendor,tests,assets/src,languages \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/ConradKurth/navexa-agecheck-for-woocommerce/issues"}'
```

The template scans the built scripts (`assets/build/`), not `assets/src/`, so its references name the files WordPress loads (what `wp i18n make-json` needs). No `load_plugin_textdomain()`: WordPress loads the `navexa-agecheck-for-woocommerce` domain just in time (from wordpress.org language packs, or `languages/` through `Domain Path`), and `wp_set_script_translations()` (the front-end block script) and block.json `textdomain` (the editor scripts) cover the JavaScript.

### wp-env

`.wp-env.json` runs the latest WordPress with WooCommerce 11.3.0 and this plugin, and points the plugin at the mock engine through `NAVEXA_AGECHECK_API_URL=http://host.docker.internal:8787`. wp-env 12 maps `host.docker.internal` to the host on Linux too.

```sh
node tests/mock-agecheck/server.mjs --host 0.0.0.0 --port 8787 &
npm run env start
npm run smoke
# W2 pre-payment flow over HTTP
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/prepayment-setup.php
node tests/wp-env/prepayment.mjs --site http://localhost:8888 --mock http://localhost:8787 --product <product> --classic <classic_checkout> \
  --exempt <exempt_product> --order-pay <order_pay>
# the orders that flow placed: bound by AttachOrder, held, released; reused drafts not held; the order-pay order bound by an order-bound check
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/prepayment-orders.php
# W3 PRs 12 and 14 (needs prepayment-setup.php first): links, customer_ref and reuse on real orders, then over HTTP
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w3b.php
node tests/wp-env/w3b.mjs --site http://localhost:8888 --mock http://localhost:8787 --product <product>
# W4 (needs prepayment-setup.php first; optionally `wp plugin install woocommerce-gateway-stripe --activate` to ask Stripe's own helper)
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w4.php
# W5 (needs prepayment-setup.php first): emailed links withdrawn on cancelled, refunded, failed, trashed and deleted orders
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w5.php
# last: uninstall.php with the plugin skipped, as WordPress runs it (never `wp plugin uninstall`: the plugin directory is this checkout)
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp --skip-plugins=navexa-agecheck-for-woocommerce eval-file tests/wp-env/w4-uninstall.php
# the entry age gate: HTTP checks, then Chromium (it switches the gate's settings itself through gate-setup.php)
node tests/e2e/gate-browser.mjs --site http://localhost:8888 --mock http://localhost:8787 --browsers chromium
# the same in browsers (npx playwright-core install chromium webkit firefox)
node tests/e2e/prepayment-browser.mjs --site http://localhost:8888 --mock http://localhost:8787 \
  --product <product> --block <block_checkout> --classic <classic_checkout> --free-product <free_product> --browsers chromium,webkit,firefox
# Turnstile's interactive challenge: Turnstile on, site key 3x00000000000000000000FF, secret 1x0000000000000000000000000000000AA
node tests/e2e/prepayment-browser.mjs --site http://localhost:8888 --mock http://localhost:8787 \
  --product <product> --block <block_checkout> --classic <classic_checkout> --browsers chromium,webkit,firefox --turnstile-interactive
npm run env destroy -- --force
```

Every browser run starts age checks from one IP, and the start limit is 20 an hour per IP: between runs, empty the counters (`wp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}navexa_agecheck_rate_limits");'`), or a later check reads "Too many age check attempts".

The smoke runs the W0 and W2 checks and then `tests/wp-env/smoke-hold.php` (real WooCommerce orders through the hold, release, webhook, override, denial, pickup, ship-to guard and order-pay refusal, ReportShipment, the sweep, per-order queueing on the bundled Action Scheduler, and a copied order). It expects a fresh environment.

On a shared machine, give the environment its own ports in `.wp-env.override.json`, map the plugin under its real directory name, and point it at a mock on a free port:

```json
{
	"port": 8892,
	"testsPort": 8893,
	"plugins": ["https://downloads.wordpress.org/plugin/woocommerce.11.3.0.zip"],
	"mappings": { "wp-content/plugins/navexa-agecheck-for-woocommerce": "." },
	"config": { "NAVEXA_AGECHECK_API_URL": "http://host.docker.internal:8798" }
}
```

The plugin directory must be named `navexa-agecheck-for-woocommerce` (the smoke checks the basename): in a worktree with another name, the mapping above does it; then `wp plugin activate navexa-agecheck-for-woocommerce`.

## Pre-payment (W2)

Contract (plan §2.7): the browser posts `{v: 1, action: "start"|"refresh"}` to `POST /wc/store/v1/cart/extensions` (namespace `navexa-agecheck`; `extensionCartUpdate` on block checkout, a fetch with the Store API `Nonce` header from `GET /wc/store/v1/cart` on classic pages) and reads `extensions["navexa-agecheck"] = {v, required, status, hosted_url?, message?}` from the cart response. Statuses: `none | pending | passed | failed | refused | unavailable`. The browser never sees a verification id, a guard or a key.

- **Start** (start key only): one verification per (session, ship-to MAC), `CreateVerification` with no DOB, `intended_ship_to`, `document_name_check=NONE` (owner: no name match before the order exists; the engine accepts NONE with a name, and unset would mean REQUIRED once a name is sent), the identity for the engine's data match (`Start::identity()`: billing first + last name, whitespace collapsed, and the billing address as `residential_address`, through `OrderAddress::normalize()` like the ship-to, only together and only when both names and a complete US billing address are filled in and that address is the ship-to by the engine's own comparison, `OrderAddress::same_residence()`; otherwise no identity, so the shopper takes the ID + selfie check; no email), `return_url` = the return page with `popup=1`, idempotency key `{install}:{sid}:{shipToMac}:{n}`; then `CreateHostedSession`. Limits per hour: 10 starts per WooCommerce session and 20 per client IP (refresh 60/120), filter `navexa_agecheck_rate_limits`.
- **Refresh**: `GetVerification`; PASSED with a clearing pass class in the key's mode mints the guard into the session (`PASS_CLASS_DATA_VERIFIED` clears like `DOC_ONLY`).
- **Data match** (engine E5, `data_then_doc`): the engine runs it only when the ship-to (`intended_ship_to`) is a US address equal to the residential address (ZIP+4 read as its ZIP) and the hosted page has the DOB and data consent (E4b: `AWAITING_IDENTITY`, served by the same hosted link). A shopper billed elsewhere is never eligible, so the start sends no identity for them (nothing would use it) and they verify by ID; nothing else changes. The classic checkout's update (`update_order_review`) saves the billing address but not the name, so `Checkout\Classic::remember_billing_name()` copies the typed name into the session customer, as the block checkout's Store API does. The mock models eligibility (`org.passPolicy`, `awaitingIdentity`, `dataConsent`; `GET /__mock/verifications` reports each one's `dataMatch` route).
- **Checkout** (classic `woocommerce_after_checkout_validation`, block/Store API/express `woocommerce_checkout_validate_order_before_payment`): pre-payment mode only; skipped for local pickup (owner; `Requirement::pickup_method_ids()`, the same list the hold path uses) and for carts and orders the Requirement rules do not restrict (`Requirement::cart_required()` for classic, `Requirement::evaluate()` for the Store API draft, which writes nothing so a refused draft is not stuck as required). Non-US ship-to refused (US, PR, GU, VI, AS, MP, UM and APO/FPO accepted). The guard must match the session and the ship-to MAC, and `GetVerification` (fulfilment key) must say PASSED, clearing class, right mode, not bound elsewhere. Any error refuses. A resumed order already bound (retried payment) is admitted only at its bound ship-to (`_navexa_agecheck_ship_to`, the hash Binding records), on the order and, on block checkout, in the shopper's request too; otherwise it is dropped from the session and a new order is decided on the session's own check (a check already passed for the new ship-to is kept: classic admits the new order in the same request, blocks ask the shopper to place the order again). Order-pay through the Store API is left to the accept-and-hold path (owner): `Checkout\OrderPay` decides existing orders and skips the order a cart checkout is placing (`Blocks::is_cart_checkout()`).

Hand-off to the hold path: once the order is placed, `Binding` moves the verification the gate admitted in that request (`Checkout\Gate::admitted_verification_id()`, the session's `KEY_VID`) onto the order; Store API order-pay and accept-and-hold orders bind nothing there and get an order-bound verification. It records it with the hash of its ship-to, drops the id, guard and record from the session (`Session::forget_verification()`), and binds it with `AttachOrder`. The checkout's `intended_ship_to` and the order's `ship_to` both come from `OrderAddress::normalize()`, so the engine never answers `ship_to_changed` for an address the plugin treats as the same. Payment then holds the order like any other until `GetClearance` answers CLEARED.

Spike results (Playwright builds of Chromium 1248, WebKit 2370, Firefox 157; WC 11.3.0 and 9.9.0):
- The loader's popup reads `popup.closed === true` and the popup's `window.opener` is `null` once it reaches a page with `Cross-Origin-Opener-Policy: same-origin` (the mock hosted page sends it, as Didit does), in all three engines. The loader therefore never reads `closed` after navigation.
- The return page's `window.close()` closed the popup in all three engines, after the COOP swap.
- A blocked popup falls back to a full-page redirect; the return page sends the tab back to checkout (sessionStorage flag) and the loader refreshes there.
- Block render: a checkout inner block forced into `woocommerce/checkout-payment-block` (`lock.remove`) renders on checkout pages saved before the plugin, on WC 9.9.0 and 11.3.0, without an editor save. WooCommerce renders no payment step for carts that need no payment, so a second forced block in `woocommerce/checkout-fields-block` shows the same button only then. Client-side gating uses a validation-store error, not `onCheckoutValidation`.
- Classic pages use the Store API directly: `GET /cart` answers the `Nonce` header after loading the guest's session, and `POST /cart/extensions` accepts it (guests and signed-in shoppers, who also send `X-WP-Nonce`).
- Not verified: real Safari (WebKit build only), mobile browsers' popup handling, and the engine's own E9 CORS (the mock implements the documented contract).

Use `.wp-env.override.json` (git-ignored) to change ports, the mock URL, WordPress or WooCommerce versions. CI runs the smoke on eight legs: WordPress 6.7 + WooCommerce 9.9.0 and the latest WordPress + WooCommerce 11.3.0, each on PHP 7.4 and 8.3, each with HPOS on and off. WooCommerce 9.9.0 needs WordPress 6.7, so the plan's WordPress 6.5 leg cannot exist. Not covered yet: WooCommerce 9.9.0 on the latest WordPress, and WooCommerce Subscriptions (W1).

## Links and reuse (W3 PRs 12, 14)

Contract: tax `proto/agecheck/v1/verification.proto` at d6dead63. `SendVerificationLink {idempotency_key, verification_id, return_url?} -> {link_id, expires_at, email_queued}`; `RevokeVerificationLinks {idempotency_key, verification_id} -> {revoked_count}`; `CreateVerification.customer_ref`; `PASS_CLASS_REUSED`.

- **Who is emailed** (owner Q7, Q11b): a held order whose verification this plugin created for it (`BOUND_BY=create`: `document_name_check` REQUIRED with the billing name, `identity.email` the billing email), while it waits on the shopper (`needs_doc`, `doc_pending`, `awaiting_identity`). Post-payment accept-and-hold and pay-for-order both land there. A checkout verification (name check NONE) or an adopted one is never emailed.
- **One ask per verification** from the release run (`Links::send()`), with the idempotency key `vl:{vid}:{random}` stored on the order before the call and reused until an answer is recorded. The answer goes in `_navexa_agecheck_link` (`{state}:{vid}`), `_link_id`, `_link_expires_at`, `_link_sent_at`, with an order note per change. The return URL signed into the link is the plugin's return step with a keyed tag of the order, only when GetIntegrationInfo says the store origin is allowed; never the order key.
- **Refusals** (FAILED_PRECONDITION, told apart by the engine's sentinel text, never logged): links not enabled (`links_off`) and no display name (`no_display_name`) are about the whole store, as is an engine without the RPC (`unavailable`): stored once in `navexa_agecheck_link_block`, and for an hour every other held order records the same state without a call (at most one call an hour store-wide), the order is still polled every sweep (the shopper can pass from Verify now, and AgeCheck's page only links back), and WooCommerce screens show a dismissible notice ("AgeCheck is not sending verification emails yet": `AGECHECK_LINKS` is one switch for the whole deployment, not per account / "set your store name in the AgeCheck dashboard", hidden once GetIntegrationInfo reports a name) for a week after the last refusal. A display name reported by GetIntegrationInfo lifts a no-display-name block at once; a sent link lifts any block. A staff resend that AgeCheck refuses never replaces a link still on record as open (AgeCheck changed nothing, so Withdraw stays offered). A link failure the sender does not read as an answer never stops the run's GetClearance; it is rethrown after it only while the order still waits (retry, or staff review). No email on the verification (`no_email`), not waiting on the shopper (`not_allowed`), another refusal or a bad return URL (`refused`) are final for that verification. RESOURCE_EXHAUSTED "verification links sent" is the 5-sends-per-24-hours budget (nothing sent); any other RESOURCE_EXHAUSTED is the key's rate limit (retried by the job with its key).
- **Staff** (Order actions, `manage_woocommerce` and the order form nonce, under the order's lock): "AgeCheck: resend verification email" (a new link; the earlier one stops working; ignores the store-wide block; the budget answer says "already emailed 5 times in 24 hours") and "AgeCheck: withdraw verification link" (`RevokeVerificationLinks`; a release run never sends a withdrawn verification a new link).
- **Verify now**: the order-received page (classic and the block order confirmation, through `woocommerce_thankyou`) and My account > view order show a "Verify now" form for a held order that waits on its shopper. It posts the order id, the order key and a nonce that WooCommerce ties to the guest's session (actions named `woocommerce*`) to `?wc-api=navexa_agecheck_order&step=start`; an account's order also needs that customer signed in; 10 starts an hour per order and 30 per IP. No verification yet: a release run in the request. Then `CreateHostedSession` with the start key and a 303 to AgeCheck's page. Its return (`step=return`, the order's keyed tag, the nonce) queues a release run and sends the browser that started it back to the order page; an emailed link's return shows a short "finished" page.
- **customer_ref** (`CustomerRef`): sent on the pre-payment start for a signed-in shopper (the idempotency key gains `:c{16 hex}` so a guest who signs in mid-checkout never replays a guest create) and on an order-bound create for an order with an account; guests none. While AgeCheck's per-store reuse is on, a returning customer's create answers PASSED `PASS_CLASS_REUSED` at once: it clears like any pass, nobody is emailed, and the panel shows "Passed by: Returning customer (reused verification)". Confirming a copied site rotates the guard secret and so every reference. Cross-store reuse stays off. Subscription renewals still wait for staff (`Subscriptions`).
- The mock models all of it: `links` `on`/`off` (default, as production)/`absent`, the engine's refusal order and texts, the budget, supersede and revoke, test mode emailing only `org.confirmedRecipients`, and `reuse` `on`/`off` (default) with `org.reuseWindowDays`.

## Hardening (W4)

### Express payment buttons

Spike (2026-11-15): sources of WooPayments 11.3.0, WooCommerce Stripe Gateway 11.2.0 and WooCommerce PayPal Payments 4.2.2 (the wordpress.org zips) read for how each express button places its order, and whether that order passes the plugin's pre-payment gates (classic `woocommerce_after_checkout_validation`, Store API `woocommerce_checkout_validate_order_before_payment`). "Hidden by" are the gateways' own filters `Express` uses while "Hide express checkout buttons on age-restricted carts and products" (`navexa_agecheck_hide_express`, default on) is on. Whatever gets through, the W1 hold still applies: a paid restricted order goes to "Awaiting age check" (`payment_complete` interception and the pre-save backstop), never to Processing.

| Gateway | Button | Pages | How the order is placed | Pre-payment gate | Hidden by | If it still gets through |
|---|---|---|---|---|---|---|
| WooPayments 11.3.0 | Apple Pay, Google Pay, Amazon Pay (Express Checkout Element) | product | Store API `POST /wc/store/v1/checkout` in a separate tokenized-cart session (`dist/express-checkout.js` `placeOrder`, `X-WooPayments-Tokenized-Cart`) | Store API gate runs; that session holds no guard, so it refuses ("Verify your age on the checkout page") | `wcpay_payment_request_is_product_supported` (button helper :1160) | refused before payment; held after payment in accept-and-hold |
| | | cart, checkout (classic and block) | Store API `/checkout` in the shopper's session | Store API gate; admitted only with the session's guard for the same ship-to | `wcpay_payment_request_is_cart_supported` (:907); on block pages a false answer keeps `wcpayExpressCheckoutParams` unset, so the block methods' `canMakePayment` answers false | as above |
| | | pay for order | Store API `/checkout/{id}` | `Checkout\OrderPay`: accept and hold (Q11b) | not hidden (Q11b): WooPayments treats order-pay as the checkout and asks `wcpay_payment_request_is_cart_supported` about the session cart, so `Express::supported_for_cart()` answers as the gateway decides there | held, link emailed (E8c) |
| | WooPay | product, cart, checkout | WooPay, then the Store API checkout in a WooPay session | Store API gate; no guard, refused | `wcpay_woopay_button_is_product_supported`, `wcpay_platform_checkout_button_are_cart_items_supported` (WooPay button handler :406, :436) | as above |
| | Link | checkout | a card-form payment method (UPE), not an express button: the normal checkout submit | classic or Store API gate | n/a | n/a |
| Stripe 11.2.0 | Apple Pay, Google Pay, Link, Amazon Pay (Express Checkout Element) | product | `wc_stripe_add_to_cart` into the shopper's session, then Store API `POST /wc/store/v1/checkout` (`build/express-checkout.js` `expressCheckoutECECreateOrder`) | Store API gate; admitted only with a guard this session earned for the same ship-to | `wc_stripe_hide_payment_request_on_product_page` (ECE helper :1208) | refused / held |
| | | cart, checkout (classic and block) | Store API `/checkout` | Store API gate | `wc_stripe_show_payment_request_on_cart` (:1161), `wc_stripe_show_payment_request_on_checkout` (:1179); block pages read the same decision (`WC_Stripe_Blocks_Support` `shouldShowExpressCheckoutButton`, :383) | refused / held |
| | | pay for order | Store API `/checkout/{id}` | `Checkout\OrderPay` | not hidden (Q11b): order-pay is `is_checkout()` to Stripe, so it asks `wc_stripe_show_payment_request_on_checkout`, which answers as Stripe decides there | held |
| | agentic commerce | none | `wc_create_order()` (`agentic-commerce/...-order-mapper.php`) | none | n/a | held |
| PayPal Payments 4.2.2, SDK v6 (the default: `modules.php` loads `ppcp-sdk-v6` unless the option `woocommerce-ppcp-sdk-v6-eligible` is `no`, which only an unsupported merchant country sets) | PayPal, Pay Later, Venmo, Apple Pay, Google Pay | product, cart, mini-cart (classic) | approve, then either back to checkout ("final review") or the order is created from the cart by `WooCommerceOrderCreator` and paid (`SdkV6Module` :212-214: every v6 express payment) | `WooCommerceOrderCreator`: **bypasses both gates** | v6 swaps the v5 `SmartButton` for `DisabledSmartButton` on every page it owns (`ppcp-sdk-v6/extensions.php`), so v5's "disabled" filters are never asked; its wrappers print on the renderer hooks it shares with v5 (`SdkV6Module::register_render_hooks()`): `woocommerce_paypal_payments_single_product_renderer_hook`, `..._proceed_to_checkout_button_renderer_hook`, `..._mini_cart_button_renderer_hook`. `Express` hands PayPal its own hook (`navexa_agecheck_paypal_{product,cart,mini_cart}`) and forwards to it at PayPal's priority (31, 20, 30) only when the product or cart is not restricted at render time; no wrapper, no buttons | held after payment |
| | | block cart, block checkout (express area) | as above | as above | `woocommerce_paypal_payments_blocks_payment_method_data` (`V6PaymentMethod::get_payment_method_data()`): `buttons_enabled` false (its block script registers the PayPal express methods only when true) and `apple_pay`/`google_pay` `styles` emptied (each wallet registers only when its styles name the page) | held |
| | | classic checkout | the button posts the checkout form (`form_encoded`, then the form submits) | classic gate | not hidden (the payment method) | n/a |
| PayPal Payments 4.2.2, SDK v5 | PayPal smart buttons (PayPal, Pay Later, Venmo) | product, cart, mini-cart (classic) | approve, then back to checkout ("continuation") and Place order; with PayPal's "Pay now" (`enable_pay_now`, never on checkout) `wc_create_order()` + `process_payment()` (`PayNowOrderHandler::pay()`); cross-browser App Switch creates the order and sends the shopper to pay-for-order | continuation: gated; Pay now and App Switch: **bypass both gates** | the renderer hooks above (v5's `SmartButton` reads the same filters), and `woocommerce_paypal_payments_product_buttons_disabled`, `woocommerce_paypal_payments_buttons_disabled` with `cart`, `mini-cart` (SmartButton :1285, :1290; the classic button script disables the button) | held after payment (the hold, verified in wp-env with `wc_create_order()` + `payment_complete()`); App Switch orders through `Checkout\OrderPay` |
| | | block cart, block checkout (express area) | the same approve flow; express buttons exist only with `smartButtonsEnabled` | as above | `woocommerce_paypal_payments_blocks_payment_method_data` (PayPalPaymentMethod :213) sets `smartButtonsEnabled` false; PayPal stays a block payment method only in its "Place order" mode | held |
| | | classic checkout | the PayPal button is the payment method: `CheckoutFormValidator` runs `WC_Checkout::validate_checkout()` (so the classic gate) before PayPal opens, then the form submits | classic gate | not hidden (not an express button) | n/a |
| | Apple Pay (PayPal) | product, cart | `ApplePayButton` posts `WC()->checkout()->process_checkout()` | classic gate | not verified that it follows the disabled flag (its boot script does not read `button.is_disabled`) | refused / held |
| | Google Pay (PayPal) | product, cart | the approve flow above | as smart buttons | not verified, as Apple Pay | held |

What was verified, and how:
- Every "Hidden by" filter answers through `Express` in unit tests and in wp-env with real products, a variable product and a real session cart (`tests/wp-env/w4.php`, WooCommerce 9.9.0 and 11.3.0).
- WooCommerce Stripe Gateway 11.2.0's own `WC_Stripe_Express_Checkout_Helper` (product, cart and checkout decisions) in wp-env on WooCommerce 11.3.0: no Express Checkout Element for a restricted product or cart, Stripe's own answer for an exempt one.
- Express-style orders made outside both checkouts (`wc_create_order()` + `payment_complete()`, as PayPal's Pay now and Stripe's agentic orders do; payment methods `ppcp-gateway`, `woocommerce_payments`, `stripe`) are held, in wp-env on both legs.
- A Store API checkout without the session's guard is refused (`tests/wp-env/prepayment.mjs`, "block checkout without a check is refused"): the path WooPayments' tokenized cart and Stripe's Express Checkout take.
- PayPal SDK v6: read in the 4.2.2 source (`ppcp-sdk-v6`), and the renderer-hook forwarding and the block data in unit tests and wp-env; PayPal Payments itself was not installed (its stacks render nothing without a connected account).
- Not verified: the wallet buttons rendering or disappearing in a browser (needs connected WooPayments, Stripe and PayPal accounts, for PayPal a sandbox account), WooPayments' and PayPal's own helpers executed (both need a connected account to get that far), and PayPal SDK v5's Apple Pay and Google Pay buttons following the disabled flag. Other gateways' express buttons are not hidden; the gates and the hold still apply to their orders.
- Decided when the page renders: a block cart that becomes restricted afterwards (a cross-sell added in the cart block) keeps buttons shown at load, and WooPayments loads its express scripts on an empty block cart. The gates and the hold cover those orders.

### Turnstile

Off by default (`navexa_agecheck_turnstile`). On, the checkout pages load Cloudflare's `api.js?render=explicit` and `assets/js/turnstile.js`; a start renders an execute-mode, interaction-only widget in the Verify age box after the popup opens (so it never blocks the popup), and sends the single-use token as `data.turnstile`. `Turnstile::verify()` posts it with the secret and the client IP to `challenges.cloudflare.com/turnstile/v0/siteverify` (8 s, no redirects) after the rate limits and before any AgeCheck call: no token or a refused one answers `refused` with "The security check did not pass", Turnstile on without both keys or Cloudflare not answering answers `unavailable`. Refresh is never checked. When Cloudflare needs the shopper to interact (`before-interactive-callback`), the challenge shows in the Verify age box on the checkout page, not in the popup: the start gives up at once (no token wait, nothing sent to the store, no rate-limit count), the popup closes, the status says "Complete the security check shown below, then select Verify age again" (announced; the classic live region, the block status line), and the focus moves to the widget's "Security check" group; the solved challenge's token is kept (at most 240 s) for the next click. The setup checklist flags Cloudflare's test keys (`1x`/`2x`/`3x`): an error with live AgeCheck keys, a warning with test keys. Verified in wp-env against Cloudflare's always-pass and always-fail test secrets, in Chromium with the always-pass site key, and with the force-interactive site key (`3x00000000000000000000FF`, `tests/e2e/prepayment-browser.mjs --turnstile-interactive`: popup closed, copy, focus, challenge solved, next click opens the age check) in Chromium, WebKit and Firefox, on block and classic checkout. Right after the popup closes Firefox ignores `focus()` for a moment, so the focus move is retried briefly (`a11y.ts` `focusOnReturn()`, the block's focus effect).

### Privacy, i18n, accessibility, uninstall

- Privacy: `Privacy` suggests a policy section (what is sent to AgeCheck and kept) and registers an exporter and an eraser for the requester's orders (billing email or the account with that email, ten a page). The eraser follows WooCommerce's "Remove personal data from orders on request" (and `woocommerce_privacy_erase_order_personal_data`), never touches an order still held, waiting for a manual refund or still able to ship (any status but completed, cancelled, refunded, failed or trash: the verification id locks its ship-to), removes the verification ids, verification status, reason, link state and ship-to hash, and keeps the release state so an erased order is never held again. It runs before WooCommerce's own order eraser (priority 5; WooCommerce's anonymizes the billing email and customer id the orders are found by), and the same erasure runs on `woocommerce_privacy_before_remove_order_personal_data`, so WooCommerce's bulk "Remove personal data" action and its retention cleanup take the plugin's meta too. `AddressGuard` does not undo WooCommerce's anonymization of the shipping address. Checked in wp-env by running every registered eraser in order, as wp-admin does.
- i18n: every PHP string uses the text domain (phpcs `WordPress.WP.I18n`); `I18nTest` fails when one is missing from the template.
- Accessibility (WCAG 2.1 AA): native button; `aria-disabled` instead of `disabled` while busy (the focus stays); `aria-busy`; the button is described by the status line; classic checkout announces through one polite live region outside the replaced fragment, the block's status line is the live region; after a check the shopper started, the focus goes to the status line on a pass (the button is gone) or back to the button. Colour contrast is the theme's (theme button classes; no colours of our own). Verified in Chromium, WebKit and Firefox (keyboard Enter, popup, focus after return, live region).
- Uninstall: `Uninstall` (run by `uninstall.php` without the plugin loaded) deletes every `navexa_agecheck_*` option and transient, the rate-limit table, the plugin's Action Scheduler group and WP-Cron hook and the dismissed-notice user meta, on every site; keeps order meta and product settings unless "Delete data on uninstall" (`navexa_agecheck_delete_data_on_uninstall`, default off) is on; moves orders still "Awaiting age check" to On hold with a note (never released) with the status registered for the move, so WooCommerce does not read it as "pending" and email the shopper "Order on hold" (found in wp-env).

## Link withdrawal (W5)

An order that will not ship should not leave a working verification link in the shopper's inbox: the shopper could still verify (a billable pass) for nothing. `LinkRevoke` therefore asks AgeCheck to withdraw the order's links (`RevokeVerificationLinks {idempotency_key, verification_id} -> {revoked_count}`, E8c; any status, and it works while `AGECHECK_LINKS` is off, which gates sending only) when:

- an order this plugin held (`_navexa_agecheck_held=yes`, or leaving "Awaiting age check") on a verification AgeCheck may have emailed (any but a checkout verification, `BOUND_BY=attach`, which passed before the order existed and is never emailed), or any order with an emailed link on record as possibly open (`sent`, `not_queued`, or a send whose answer is not recorded), moves to cancelled, refunded or failed (`woocommerce_order_status_changed`), is refunded in full whatever status a filter kept (`woocommerce_order_fully_refunded`), is trashed (`woocommerce_trash_order`, and `trashed_post` for the posts table's own Orders screen), or is deleted for good (`woocommerce_before_delete_order` on both order stores, and `before_delete_post` for the posts table's own screens, "Empty Trash" included). A partial refund withdraws nothing;
- the verification is the order's own (never one copied from another order: that copied state is dropped) and was not withdrawn already with nothing sent since;
- the order's external id carries this site's current install_id. A copied site whose admin confirmed it keeping the live keys would otherwise withdraw the original site's shoppers' links when its copied orders are cleaned up; an order bound before this site's own last "Confirm this site" (a domain move) looks the same and is skipped too (logged `reason=other_install`; staff can still withdraw its link).

The status change only records `_navexa_agecheck_revoke=pending:{vid}` and queues `navexa_agecheck_revoke_links` (`Release::queue_once()`); it never calls AgeCheck and nothing it does throws. The run takes the order's lock, stores `rvk:{vid}:{random}` in `_navexa_agecheck_revoke_key` before the call and reuses it until an answer is recorded, and skips an order that can ship again by then (restored from the trash, back to "Awaiting age check") unless a call was already made. A deleted order's action carries the verification id and the key (`[order, attempt, vid, key]`, the stored key when the order was trashed first and already asked), because the order and its meta are gone before it runs; that run only asks and logs the answer. Retryable failures retry with backoff and Retry-After (8 times, about 3 hours), then are recorded; other failures (no key, a paused copied site, a refusal) are recorded at once. The answer is `revoked:{vid}` or `failed:{vid}` with `_navexa_agecheck_revoke_detail` (links still open, or the error code), `_navexa_agecheck_revoke_at` and an order note; a link on record as open becomes `revoked` (the panel says so; a release run sends that verification no new link while the order will not ship, and staff may resend). An order held again (a failed order paid again, an order restored) gets a new link from its next release run. A failure leaves the link on record as open, so "AgeCheck: withdraw verification link" stays offered. Anything else a run throws is retried like an outage; a withdrawal still pending six hours after it was asked for (an action that died, timed out or was never queued) is queued again by the sweep, at most once per six hours, so no withdrawal stays pending for good (while one is pending, the privacy eraser keeps the order's AgeCheck details). Not covered: links of verifications an order was bound to before (`_navexa_agecheck_previous_vids`).

## Releasing

The zip is the release: GitHub Releases first, wordpress.org later (owner decision Q12).

1. On a branch: set the version in the plugin header (`Version:`), `NAVEXA_AGECHECK_VERSION` and readme.txt's `Stable tag:`, add a `= x.y.z =` entry to readme.txt's Changelog (and Upgrade Notice), bump `package.json`'s `version` to match, and regenerate the translation template (Development, above) so its `Project-Id-Version` follows. Merge it to `main` with CI green.
2. Dry run on that commit (Docker and Node 24):
   ```sh
   bin/build-zip.sh                 # dist/navexa-agecheck-for-woocommerce-x.y.z.zip + dist/SHA256SUMS, from HEAD
   bin/build-zip.sh --worktree      # the same from uncommitted files (a check only, never a release)
   bin/verify-zip.sh dist/navexa-agecheck-for-woocommerce-x.y.z.zip --port 8896
   bin/verify-zip.sh dist/navexa-agecheck-for-woocommerce-x.y.z.zip --wc 9.9.0 --core https://wordpress.org/wordpress-6.7.zip --php 7.4 --port 8896
   ```
   `build-zip.sh` builds with `git archive`, so `.gitattributes` `export-ignore` decides what stays out, then refuses a zip whose versions disagree, whose readme.txt Changelog section (not its Upgrade Notice) lacks the version's entry, or that holds anything but the plugin's PHP, `assets/` (the builds, every minified script's unminified build, and the sources in `assets/src/` with the loader's build script, for wordpress.org Guideline 4; the npm and webpack configuration is on GitHub, as readme.txt says), `readme.txt`, `languages/` and `LICENSE`. Entries are sorted, timestamped with the commit's time and written with fixed permissions, so the same commit gives the same bytes (with the same Python and zlib). `verify-zip.sh` installs the zip into a throwaway wp-env that mounts only the zip (never this checkout), checks it activates and boots with WooCommerce, ships no development files and logs no PHP errors, then uninstalls it and destroys the environment.
3. Tag the merged commit on `main` and push the tag: `git tag -a vx.y.z -m "x.y.z" <commit> && git push origin vx.y.z`.
4. `.github/workflows/release.yml` then refuses a tag that is not `v` + the version, not on `main`, or that disagrees with the plugin header `Version` or readme.txt's `Stable tag`; builds the zip twice from the checkout and compares, in a job that runs nothing from npm (no dependency's install script or build code can touch the build script, `.git` or the archived sources); checks in a separate job that the committed builds match their sources; installs it in wp-env on WordPress 6.7 + WooCommerce 9.9.0 + PHP 7.4 and the latest WordPress + WooCommerce 11.3.0 + PHP 8.3; and creates a **draft** GitHub Release for the tag with the zip and `SHA256SUMS`, after checking the zip against the SHA-256 the build job reported. Nothing is published by the workflow.
5. Review the draft (download the zip, `sha256sum -c SHA256SUMS`), then publish it by hand.

wordpress.org is a separate step, after AgeCheck self-serve is `open` and only with the owner's approval: the plan (design-final.md §5) reads wordpress.org Guideline 5 as barring a plugin that only works in test mode or for invited accounts, and every release there is public at once. Then: submit the release zip at wordpress.org/plugins/developers/add/, and once approved, commit the same zip's contents to the plugin's SVN `trunk/` and `tags/x.y.z/` (readme.txt's `Stable tag` names that tag). No workflow writes to SVN.

## wp-config constants

| Constant | Effect |
|---|---|
| `NAVEXA_AGECHECK_API_URL` | API base URL (default `https://verify.navexa.co`). Never a setting, so the admin UI cannot send keys elsewhere. |
| `NAVEXA_AGECHECK_ALLOW_TEST_RELEASE` | `true` lets test keys auto-release held orders (CI and staging only). |
| `NAVEXA_AGECHECK_EMAIL_LINKS` | Kill switch, on by default. Unset (or `true`): AgeCheck decides whether held orders' shoppers are emailed a verification link (`SendVerificationLink`, E8c; it refuses while `AGECHECK_LINKS` is off for the deployment or the store has no display name). `false`: the plugin never asks; held orders stay held and the panel says "turned off on this site". |
