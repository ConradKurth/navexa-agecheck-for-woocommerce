# Navexa AgeCheck for WooCommerce: agent notes

The plan is §5 of `~/.cache/agecheck-gap-2026-11-13/design-final.md` (layout, milestones W0-W5, hooks table). The engine contract is in the tax repo's `proto/agecheck/v1/verification.proto` and CLAUDE.md (AgeCheck sections). README.md has the commands.

## Rules
- **Fail closed.** Any `ConnectError`, a missing key, an unknown mode or a paused install refuses: never admit, never release. There is no fail-open setting.
- **Server decides.** Admission needs `GetVerification` PASSED with a clearing pass class; release needs `GetClearance` CLEARED. Both go through `Mode::accepts_livemode()`, and release also through `Mode::may_auto_release()`. Browser-sent ids are hints only.
- **No PII or secrets in logs.** Use `Client::log()` with ids and codes only. Never log a key, a request body, `ConnectError::server_message()` or a hosted URL.
- **Request paths make one attempt, with no sleeps.** Action Scheduler jobs reschedule on `ConnectError::is_retryable()` after `retry_after()`. A bodiless 500 or Cloudflare 520-526 reads as `unavailable` (retryable); a Connect body's own code always wins.
- **HPOS only.** Use `wc_get_order`, order CRUD meta and `wc_get_orders`. Never post meta or `WP_Query` on orders.
- **Names.** Options are `navexa_agecheck_*`, order meta `_navexa_agecheck_*`, hooks `navexa_agecheck_*`, namespace `Navexa\AgeCheck`, text domain `navexa-agecheck-for-woocommerce`.
- **Settings loads only on WooCommerce settings screens.** `Settings` extends `WC_Settings_Page`, so never reference it from checkout code; use `Options`.
- **Site address is `Install::home()`** (the raw `home` option, which still honours `WP_HOME`), never `home_url()`. Multilingual plugins (TranslatePress, WPML) filter `home_url` per request on the front end only, so a fingerprint or origin built from it pauses checkout while admin screens look fine.
- **Order external ids** are `wc:{install_id}:{blog_id}:{order_id}`. They are stored in order meta at bind and never recomputed, because `Install::confirm_site()` rotates the install_id.
- **`add_option()` is not a mutex.** WordPress runs `INSERT ... ON DUPLICATE KEY UPDATE` after a read, so two racing writers both "win". Locks and webhook dedupe need an atomic primitive, such as a plain `INSERT` into a plugin table with a unique key, or `$wpdb->query( "INSERT IGNORE ..." )` and a check of rows affected.

## Style
- WordPress Coding Standards (`phpcs.xml.dist`): long array syntax, Yoda conditions, snake_case methods, a docblock on everything. PHP 7.4 syntax only. `includes/` is PSR-4, so the WordPress file-name sniff is off there.
- Tests are Brain Monkey unit tests in `tests/phpunit/Unit` (in-memory options, transients and HTTP in `TestCase`), plus `tests/wp-env/smoke.php` for behaviour that only real WooCommerce shows. The mock engine (`tests/mock-agecheck/server.mjs`) gains RPCs as milestones need them. Keep its answers proto3 JSON: lowerCamelCase, zero values omitted.
- Docker for PHP (`php:8.3-cli`, `php:7.4-cli`, `composer:2`) with `-u "$(id -u):$(id -g)"`.
