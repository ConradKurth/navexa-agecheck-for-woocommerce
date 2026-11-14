# Navexa AgeCheck for WooCommerce

WordPress plugin that gates age-restricted WooCommerce orders on a Navexa AgeCheck pass. US ship-to addresses only. GPL-2.0-or-later. The wordpress.org readme is `readme.txt`.

Status: **W1 hold core** on top of the W0 scaffold. Paid age-restricted orders are held in "Awaiting age check" until AgeCheck clears them (or staff use the logged override); binding, release, the webhook receiver, the sweep, ReportShipment, the ship-to guard, denial handling, the order panel and Orders filters are in. Checkout enforcement (the Verify button and pre-payment gate) and the loader come in W2; verification emails need engine PR E8c. The plan is `design-final.md` §5 in the AgeCheck planning cache; owner decisions that override it are in `owner-decisions.md` there.

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
| `includes/Requirement.php` | Which orders need a check: store rule (all / include / exclude categories and tags), per-product setting, exempt customer roles, local pickup skipped, orders from before activation skipped; sticky once required |
| `includes/Hold.php` | `wc-agecheck-hold` status; `payment_complete` interception; the pre-save backstop; refused exits; release to the intercepted status; the logged override |
| `includes/AddressGuard.php` | Reverts ship-to edits on bound orders |
| `includes/OrderAddress.php` | Address messages from orders, the single normalizer (ZIP+4 to 5 digits), US + territories check |
| `includes/Binding.php` | External ids; re-entrant `AttachOrder` of the checkout verification; order-bound `CreateVerification`; verification links |
| `includes/LinkSender.php`, `UnavailableLinkSender.php`, `EngineLinkSender.php` | `SendVerificationLink` (E8c) behind an interface; unavailable by default |
| `includes/Release.php` | The release run: per-order lock, persisted `GetClearance` key, replayed handling, livemode gate, retries |
| `includes/Denial.php` | Failed checks: gateway refund or the manual refund list |
| `includes/Lock.php` | Atomic option-row locks and claim-once markers |
| `includes/OrderQuery.php` | `wc_get_orders()` meta queries on HPOS and on the posts table (which drops `meta_query`) |
| `includes/Webhook.php` | `POST /wp-json/navexa-agecheck/v1/webhook`, `X-Webhook-Signature-V2`, event-id dedupe |
| `includes/Sweep.php`, `Shipment.php` | 15-minute sweep; ReportShipment on completed |
| `includes/Subscriptions.php` | Renewals held for review (spike notes in the class docblock) |
| `includes/Checkout/OrderPay.php` | Pay-for-order: accept and hold; Store API ship-to change refused on bound orders |
| `includes/Admin/` | Order panel and actions, Orders filters, notices, product field |
| `tests/phpunit/` | Unit tests (Brain Monkey, no WordPress) |
| `tests/mock-agecheck/` | Zero-dependency Node mock of the engine's Connect API, with fault injection |
| `tests/wp-env/smoke.php` | Smoke checks inside a real WordPress + WooCommerce (wp-env) |

## Development

PHP is not needed on the host; Docker is enough.

```sh
# dev dependencies
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 install
# lint (WordPress Coding Standards + PHPCompatibilityWP 7.4+)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpcs
# unit tests (CI runs PHP 7.4 and 8.3)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit
# mock engine tests (Node 22.13+)
npm run test:mock
```

### wp-env

`.wp-env.json` runs the latest WordPress with WooCommerce 11.3.0 and this plugin, and points the plugin at the mock engine through `NAVEXA_AGECHECK_API_URL=http://host.docker.internal:8787`. wp-env 12 maps `host.docker.internal` to the host on Linux too.

```sh
node tests/mock-agecheck/server.mjs --host 0.0.0.0 --port 8787 &
npm run env start
npm run smoke
npm run env destroy -- --force
```

The smoke runs the W0 checks and then `tests/wp-env/smoke-hold.php` (real WooCommerce orders through the hold, release, webhook, override, denial, pickup, ship-to guard and order-pay refusal, ReportShipment, the sweep, per-order queueing on the bundled Action Scheduler, and a copied order). It expects a fresh environment.

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

Use `.wp-env.override.json` (git-ignored) to change ports, the mock URL, WordPress or WooCommerce versions. CI runs the smoke on eight legs: WordPress 6.7 + WooCommerce 9.9.0 and the latest WordPress + WooCommerce 11.3.0, each on PHP 7.4 and 8.3, each with HPOS on and off. WooCommerce 9.9.0 needs WordPress 6.7, so the plan's WordPress 6.5 leg cannot exist. Not covered yet: WooCommerce 9.9.0 on the latest WordPress, and WooCommerce Subscriptions (W1).

### wp-config constants

| Constant | Effect |
|---|---|
| `NAVEXA_AGECHECK_API_URL` | API base URL (default `https://verify.navexa.co`). Never a setting, so the admin UI cannot send keys elsewhere. |
| `NAVEXA_AGECHECK_ALLOW_TEST_RELEASE` | `true` lets test keys auto-release held orders (CI and staging only). |
| `NAVEXA_AGECHECK_EMAIL_LINKS` | `true` asks AgeCheck to email held orders' shoppers a verification link (`SendVerificationLink`, engine PR E8c). Leave unset until E8c is live. |
