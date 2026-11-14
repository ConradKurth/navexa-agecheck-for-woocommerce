# Navexa AgeCheck for WooCommerce

WordPress plugin that gates age-restricted WooCommerce orders on a Navexa AgeCheck pass. US ship-to addresses only. GPL-2.0-or-later. The wordpress.org readme is `readme.txt`.

Status: **W0 scaffold + W2 pre-payment**. Bootstrap, activation and copied-site detection, the settings tab, the API client, live/test mode detection and the mock engine (W0); the shopper's Verify age step before payment on classic and block checkout: ship-to normalization, the checkout guard, rate limits, the Store API start/refresh extension, the return page, the bundled loader and the checkout block (W2). Holds, binding and release come in W1. The plan is `design-final.md` §5 in the AgeCheck planning cache.

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
| `includes/ShipTo.php` | Ship-to normalization mirroring the engine's `ShipToCanonical` (plus NFC and ZIP+4 → ZIP), MAC under the install's key; US + territories |
| `includes/GuardToken.php` | HS256 JWT port of Zavro's scoped token; the checkout guard `{scope, sid, vid, shipto_mac, iat, exp}` |
| `includes/RateLimit.php` | Atomic fixed-window counters in `{prefix}navexa_agecheck_rate_limits` (HMAC keys, never an IP); client IP with an optional trusted proxy header; proxy warning from recent starts (one IP, private peer addresses or unread proxy headers) |
| `includes/Start.php` | Store API extension `navexa-agecheck`: start/refresh update callback and cart endpoint data |
| `includes/ReturnPage.php` | `?wc-api=navexa_agecheck_return`: BroadcastChannel + storage nudge, `window.close()`, link back to checkout |
| `includes/Frontend.php` | Registers the bundled loader, the classic glue and the checkout block scripts |
| `includes/Checkout/Gate.php` | The pre-payment admission rules both checkouts share |
| `includes/Checkout/Session.php` | The shopper's sid, counter, verification record and guard in the WooCommerce session |
| `includes/Checkout/Classic.php` | `woocommerce_after_checkout_validation` + the Verify age button above Place order |
| `includes/Checkout/Blocks.php`, `BlocksIntegration.php` | `woocommerce_checkout_validate_order_before_payment` (WC 9.9+) and the checkout block integration |
| `assets/src/loader/` | The loader (TypeScript, zero dependencies) and the classic glue; esbuild → `assets/js/` |
| `assets/src/blocks/` | The Verify age checkout inner blocks; wp-scripts → `assets/build/` |
| `tests/phpunit/` | Unit tests (Brain Monkey, no WordPress); `fixtures/ship-to-vectors.json` is exported from the engine |
| `tests/mock-agecheck/` | Zero-dependency Node mock of the engine's Connect API and hosted surface, with fault injection |
| `tests/wp-env/smoke.php` | Smoke checks inside a real WordPress + WooCommerce (wp-env) |
| `tests/wp-env/prepayment.mjs` | The W2 flow over HTTP against wp-env and the mock (setup: `prepayment-setup.php`) |
| `tests/e2e/prepayment-browser.mjs` | The W2 flow in Chromium, WebKit and Firefox (Playwright) |

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
```

### wp-env

`.wp-env.json` runs the latest WordPress with WooCommerce 11.3.0 and this plugin, and points the plugin at the mock engine through `NAVEXA_AGECHECK_API_URL=http://host.docker.internal:8787`. wp-env 12 maps `host.docker.internal` to the host on Linux too.

```sh
node tests/mock-agecheck/server.mjs --host 0.0.0.0 --port 8787 &
npm run env start
npm run smoke
# W2 pre-payment flow over HTTP
npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/prepayment-setup.php
node tests/wp-env/prepayment.mjs --site http://localhost:8888 --mock http://localhost:8787 --product <product> --classic <classic_checkout>
# the same in browsers (npx playwright-core install chromium webkit firefox)
node tests/e2e/prepayment-browser.mjs --site http://localhost:8888 --mock http://localhost:8787 \
  --product <product> --block <block_checkout> --classic <classic_checkout> --free-product <free_product> --browsers chromium,webkit,firefox
npm run env destroy -- --force
```

The plugin directory must be named `navexa-agecheck-for-woocommerce` (the smoke checks the basename). In a worktree with another name, map it in `.wp-env.override.json`: `"plugins": ["https://downloads.wordpress.org/plugin/woocommerce.11.3.0.zip"]`, `"mappings": {"wp-content/plugins/navexa-agecheck-for-woocommerce": "."}`, then `wp plugin activate navexa-agecheck-for-woocommerce`.

## Pre-payment (W2)

Contract (plan §2.7): the browser posts `{v: 1, action: "start"|"refresh"}` to `POST /wc/store/v1/cart/extensions` (namespace `navexa-agecheck`; `extensionCartUpdate` on block checkout, a fetch with the Store API `Nonce` header from `GET /wc/store/v1/cart` on classic pages) and reads `extensions["navexa-agecheck"] = {v, required, status, hosted_url?, message?}` from the cart response. Statuses: `none | pending | passed | failed | refused | unavailable`. The browser never sees a verification id, a guard or a key.

- **Start** (start key only): one verification per (session, ship-to MAC), `CreateVerification` with no DOB, the billing name/address/email the store holds, `intended_ship_to`, `document_name_check=NONE`, `return_url` = the return page with `popup=1`, idempotency key `{install}:{sid}:{shipToMac}:{n}`; then `CreateHostedSession`. Limits per hour: 10 starts per WooCommerce session and 20 per client IP (refresh 60/120), filter `navexa_agecheck_rate_limits`.
- **Refresh**: `GetVerification`; PASSED with a clearing pass class in the key's mode mints the guard into the session.
- **Checkout** (classic `woocommerce_after_checkout_validation`, block/Store API/express `woocommerce_checkout_validate_order_before_payment`): pre-payment mode only; skipped for local pickup (owner) and carts the Requirement rules exempt (filters `navexa_agecheck_cart_required` / `navexa_agecheck_order_required`; default: everything is restricted). Non-US ship-to refused (US, PR, GU, VI, AS, MP, UM and APO/FPO accepted). The guard must match the session and the ship-to MAC, and `GetVerification` (fulfilment key) must say PASSED, clearing class, right mode, not bound elsewhere. Any error refuses. A resumed order already bound (retried payment) is admitted only at its bound ship-to (`_navexa_agecheck_shipto_mac`), on the order and, on block checkout, in the shopper's request too; otherwise it is dropped from the session and a new order is decided on the session's own check (a check already passed for the new ship-to is kept: classic admits the new order in the same request, blocks ask the shopper to place the order again). Order-pay through the Store API is left to the accept-and-hold path (owner).

Spike results (Playwright builds of Chromium 1248, WebKit 2370, Firefox 157; WC 11.3.0 and 9.9.0):
- The loader's popup reads `popup.closed === true` and the popup's `window.opener` is `null` once it reaches a page with `Cross-Origin-Opener-Policy: same-origin` (the mock hosted page sends it, as Didit does), in all three engines. The loader therefore never reads `closed` after navigation.
- The return page's `window.close()` closed the popup in all three engines, after the COOP swap.
- A blocked popup falls back to a full-page redirect; the return page sends the tab back to checkout (sessionStorage flag) and the loader refreshes there.
- Block render: a checkout inner block forced into `woocommerce/checkout-payment-block` (`lock.remove`) renders on checkout pages saved before the plugin, on WC 9.9.0 and 11.3.0, without an editor save. WooCommerce renders no payment step for carts that need no payment, so a second forced block in `woocommerce/checkout-fields-block` shows the same button only then. Client-side gating uses a validation-store error, not `onCheckoutValidation`.
- Classic pages use the Store API directly: `GET /cart` answers the `Nonce` header after loading the guest's session, and `POST /cart/extensions` accepts it (guests and signed-in shoppers, who also send `X-WP-Nonce`).
- Not verified: real Safari (WebKit build only), mobile browsers' popup handling, and the engine's own E9 CORS (the mock implements the documented contract).

Use `.wp-env.override.json` (git-ignored) to change ports, the mock URL, WordPress or WooCommerce versions. CI runs the smoke on eight legs: WordPress 6.7 + WooCommerce 9.9.0 and the latest WordPress + WooCommerce 11.3.0, each on PHP 7.4 and 8.3, each with HPOS on and off. WooCommerce 9.9.0 needs WordPress 6.7, so the plan's WordPress 6.5 leg cannot exist. Not covered yet: WooCommerce 9.9.0 on the latest WordPress, and WooCommerce Subscriptions (W1).

### wp-config constants

| Constant | Effect |
|---|---|
| `NAVEXA_AGECHECK_API_URL` | API base URL (default `https://verify.navexa.co`). Never a setting, so the admin UI cannot send keys elsewhere. |
| `NAVEXA_AGECHECK_ALLOW_TEST_RELEASE` | `true` lets test keys auto-release held orders (CI and staging only). |
