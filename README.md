# Navexa AgeCheck for WooCommerce

WordPress plugin that gates age-restricted WooCommerce orders on a Navexa AgeCheck pass. US ship-to addresses only. GPL-2.0-or-later. The wordpress.org readme is `readme.txt`.

Status: **W0 scaffold**. Bootstrap, activation and copied-site detection, the settings tab, the API client, live/test mode detection and the mock engine are in. Checkout enforcement, holds and the loader come in W1 and W2. The plan is `design-final.md` §5 in the AgeCheck planning cache.

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

Use `.wp-env.override.json` (git-ignored) to change ports, the mock URL, WordPress or WooCommerce versions. CI runs the smoke on eight legs: WordPress 6.7 + WooCommerce 9.9.0 and the latest WordPress + WooCommerce 11.3.0, each on PHP 7.4 and 8.3, each with HPOS on and off. WooCommerce 9.9.0 needs WordPress 6.7, so the plan's WordPress 6.5 leg cannot exist. Not covered yet: WooCommerce 9.9.0 on the latest WordPress, and WooCommerce Subscriptions (W1).

### wp-config constants

| Constant | Effect |
|---|---|
| `NAVEXA_AGECHECK_API_URL` | API base URL (default `https://verify.navexa.co`). Never a setting, so the admin UI cannot send keys elsewhere. |
| `NAVEXA_AGECHECK_ALLOW_TEST_RELEASE` | `true` lets test keys auto-release held orders (CI and staging only). |
