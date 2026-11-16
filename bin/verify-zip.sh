#!/usr/bin/env bash
# Installs a built zip into a throwaway wp-env (WordPress + WooCommerce, no
# checkout mounted), checks it activates and boots, then uninstalls it and
# destroys the environment (milestone W5, PR 17).
#
#   bin/verify-zip.sh <zip> [--wc 11.3.0] [--core <WordPress zip URL>] [--php 8.3] [--port 8888]
#
# --wc    WooCommerce version from downloads.wordpress.org (default 11.3.0)
# --core  WordPress core zip URL (default: the latest WordPress)
# --php   PHP version (default 8.3)
# --port  HTTP port of the environment (default 8888, or WP_ENV_PORT)
#
# Checks: `wp plugin install <zip> --activate` works and the plugin is
# active at the zip's own version; it booted with WooCommerce (its classes
# load from the zip, its install state and rate-limit table exist, the
# "Awaiting age check" status is registered, HPOS compatibility is
# declared); the installed directory holds no development files; the PHP
# error log names none of its files; uninstalling runs uninstall.php
# (no navexa_agecheck_* option left) and deletes it.
#
# The environment lives in a temporary directory with only the zip mapped
# in, so nothing here can write to the checkout. Needs Docker and Node.
set -euo pipefail

slug=navexa-agecheck-for-woocommerce
wc=11.3.0
core=''
php=8.3
port=${WP_ENV_PORT:-8888}
zip=''
wp_env=(npx --yes @wordpress/env@12.1.0)

die() {
	echo "verify-zip: $*" >&2
	exit 1
}

while [ $# -gt 0 ]; do
	case "$1" in
		--wc) wc=${2:?--wc needs a version}; shift 2 ;;
		--core) core=${2-}; shift 2 ;;
		--php) php=${2:?--php needs a version}; shift 2 ;;
		--port) port=${2:?--port needs a port}; shift 2 ;;
		-h | --help) sed -n '2,/^set -euo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'; exit 0 ;;
		-*) die "unknown argument: $1" ;;
		*) [ -z "$zip" ] || die "one zip at a time"; zip=$1; shift ;;
	esac
done
[ -n "$zip" ] && [ -f "$zip" ] || die "usage: bin/verify-zip.sh <zip> [--wc 11.3.0] [--core <url>] [--php 8.3] [--port 8888]"
[[ "$wc" =~ ^[0-9]+(\.[0-9]+)+$ ]] || die "not a WooCommerce version: $wc"
[[ "$port" =~ ^[0-9]+$ ]] || die "not a port: $port"
command -v unzip > /dev/null || die "unzip is needed"
version=$(unzip -p "$zip" "$slug/$slug.php" | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -n 1)
[ -n "$version" ] || die "$zip has no $slug/$slug.php with a Version header"
name=$(basename "$zip")

tmp=$(mktemp -d)
cleanup() {
	(cd "$tmp" && "${wp_env[@]}" destroy --force > /dev/null 2>&1) || echo "verify-zip: could not destroy the environment in $tmp" >&2
	rm -rf "$tmp" 2> /dev/null || true
}
trap cleanup EXIT
mkdir "$tmp/dist"
cp "$zip" "$tmp/dist/$name"
# shellcheck disable=SC2016 # JavaScript, not shell.
node -e '
const [core, wc, php, port, file] = process.argv.slice(1)
require("node:fs").writeFileSync(file, JSON.stringify({
	core: core === "" ? null : core,
	phpVersion: php,
	port: Number(port),
	testsEnvironment: false,
	plugins: [`https://downloads.wordpress.org/plugin/woocommerce.${wc}.zip`],
	mappings: { "wp-content/navexa-dist": "./dist" },
	config: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false },
}, null, "\t") + "\n")
' "$core" "$wc" "$php" "$port" "$tmp/.wp-env.json"

cli() {
	(cd "$tmp" && "${wp_env[@]}" run cli "$@")
}

echo "verify-zip: $name (version $version) on WooCommerce $wc, WordPress ${core:-latest}, PHP $php"
(cd "$tmp" && "${wp_env[@]}" start)
cli wp plugin install "wp-content/navexa-dist/$name" --activate
cli wp plugin is-active "$slug" || die "the plugin is not active"
# wp-env adds its own status lines: read only the answer's line.
installed=$(cli wp plugin get "$slug" --field=version | tr -d '\r' | grep -xE '[0-9][0-9A-Za-z.-]*' | tail -n 1 || true)
[ "$installed" = "$version" ] || die "installed version $installed, the zip says $version"

# shellcheck disable=SC2016 # PHP, not shell.
report=$(cli wp eval '
$dir = WP_PLUGIN_DIR . "/navexa-agecheck-for-woocommerce";
$fail = array();
if ( ! did_action( "woocommerce_loaded" ) ) { $fail[] = "WooCommerce did not load"; }
if ( ! class_exists( "Navexa\\AgeCheck\\Plugin" ) || ! defined( "NAVEXA_AGECHECK_FILE" ) || realpath( NAVEXA_AGECHECK_FILE ) !== realpath( $dir . "/navexa-agecheck-for-woocommerce.php" ) ) { $fail[] = "the plugin did not boot from the zip"; }
if ( "" === \Navexa\AgeCheck\Install::install_id() ) { $fail[] = "no install_id (activation hook)"; }
global $wpdb;
if ( \Navexa\AgeCheck\RateLimit::table() !== $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", \Navexa\AgeCheck\RateLimit::table() ) ) ) { $fail[] = "no rate-limit table"; }
if ( ! array_key_exists( "wc-agecheck-hold", wc_get_order_statuses() ) ) { $fail[] = "the hold status is not registered"; }
$hpos = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( "custom_order_tables" );
if ( ! in_array( "navexa-agecheck-for-woocommerce/navexa-agecheck-for-woocommerce.php", isset( $hpos["compatible"] ) ? $hpos["compatible"] : array(), true ) ) { $fail[] = "HPOS compatibility not declared"; }
foreach ( array( "tests", "node_modules", "vendor", "bin", ".github", "composer.json", "package.json", "README.md", "CLAUDE.md" ) as $dev ) { if ( file_exists( $dir . "/" . $dev ) ) { $fail[] = "ships " . $dev; } }
$log = WP_CONTENT_DIR . "/debug.log";
if ( file_exists( $log ) ) { foreach ( file( $log ) as $line ) { if ( false !== strpos( $line, "navexa-agecheck-for-woocommerce" ) ) { $fail[] = "PHP log: " . trim( $line ); } } }
echo "\nNAVEXA_VERIFY:" . ( $fail ? "FAIL " . implode( "; ", $fail ) : "ok" ) . "\n";
' | tr -d '\r' | sed -n 's/^NAVEXA_VERIFY://p' | tail -n 1)
[ "$report" = ok ] || die "${report:-no answer from wp eval}"
echo "verify-zip: active and booted: $report"

cli wp plugin deactivate "$slug"
cli wp plugin uninstall "$slug"
if cli wp plugin is-installed "$slug" > /dev/null 2>&1; then
	die "the plugin is still installed after uninstall"
fi
left=$(cli wp option list --search='navexa_agecheck_*' --format=count | tr -d '\r' | grep -xE '[0-9]+' | tail -n 1 || true)
[ "$left" = 0 ] || die "$left navexa_agecheck_* options left after uninstall"
echo "verify-zip: ok: $name installs, activates and uninstalls cleanly"
