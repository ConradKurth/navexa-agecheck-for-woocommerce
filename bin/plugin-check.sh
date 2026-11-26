#!/usr/bin/env bash
# Runs wordpress.org's Plugin Check (plugin-check, from wordpress.org) on a
# built zip in a throwaway wp-env (WordPress + WooCommerce, no checkout
# mounted), prints every finding, and fails on any ERROR (1.1.0, design-woo
# §10). WARNINGs are printed, not fatal.
#
#   bin/plugin-check.sh <zip> [--wc 11.3.0] [--php 8.3] [--port 8888] [--keep]
#
# --wc    WooCommerce version from downloads.wordpress.org (default 11.3.0)
# --php   PHP version (default 8.3)
# --port  HTTP port of the environment (default 8888, or WP_ENV_PORT)
# --keep  leave the environment running (prints its directory)
#
# The environment lives in a temporary directory with only the zip mapped
# in, as bin/verify-zip.sh does, so nothing here can write to the checkout.
# Needs Docker, Node and unzip. The Plugin Check version is whatever
# wordpress.org serves today; its CLI form (`wp plugin check <slug>`, some
# versions also need `--require=./wp-content/plugins/plugin-check/cli.php`)
# is tried both ways.
set -euo pipefail

slug=navexa-agecheck-for-woocommerce
wc=11.3.0
php=8.3
port=${WP_ENV_PORT:-8888}
keep=0
zip=''
wp_env=(npx --yes @wordpress/env@12.1.0)

die() {
	echo "plugin-check: $*" >&2
	exit 1
}

while [ $# -gt 0 ]; do
	case "$1" in
		--wc) wc=${2:?--wc needs a version}; shift 2 ;;
		--php) php=${2:?--php needs a version}; shift 2 ;;
		--port) port=${2:?--port needs a port}; shift 2 ;;
		--keep) keep=1; shift ;;
		-h | --help) sed -n '2,/^set -euo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'; exit 0 ;;
		-*) die "unknown argument: $1" ;;
		*) [ -z "$zip" ] || die "one zip at a time"; zip=$1; shift ;;
	esac
done
[ -n "$zip" ] && [ -f "$zip" ] || die "usage: bin/plugin-check.sh <zip> [--wc 11.3.0] [--php 8.3] [--port 8888] [--keep]"
[[ "$wc" =~ ^[0-9]+(\.[0-9]+)+$ ]] || die "not a WooCommerce version: $wc"
[[ "$port" =~ ^[0-9]+$ ]] || die "not a port: $port"
command -v unzip > /dev/null || die "unzip is needed"
name=$(basename "$zip")

tmp=$(mktemp -d)
cleanup() {
	if [ "$keep" = 1 ]; then
		echo "plugin-check: environment kept in $tmp (destroy: cd $tmp && ${wp_env[*]} destroy --force)"
		return
	fi
	(cd "$tmp" && "${wp_env[@]}" destroy --force > /dev/null 2>&1) || echo "plugin-check: could not destroy the environment in $tmp" >&2
	rm -rf "$tmp" 2> /dev/null || true
}
trap cleanup EXIT
mkdir "$tmp/dist"
cp "$zip" "$tmp/dist/$name"
# shellcheck disable=SC2016 # JavaScript, not shell.
node -e '
const [wc, php, port, file] = process.argv.slice(1)
require("node:fs").writeFileSync(file, JSON.stringify({
	core: null,
	phpVersion: php,
	port: Number(port),
	testsEnvironment: false,
	plugins: [`https://downloads.wordpress.org/plugin/woocommerce.${wc}.zip`, "https://downloads.wordpress.org/plugin/plugin-check.latest-stable.zip"],
	mappings: { "wp-content/navexa-dist": "./dist" },
}, null, "\t") + "\n")
' "$wc" "$php" "$port" "$tmp/.wp-env.json"

cli() {
	(cd "$tmp" && "${wp_env[@]}" run cli "$@")
}

echo "plugin-check: $name on WooCommerce $wc, the latest WordPress, PHP $php"
(cd "$tmp" && "${wp_env[@]}" start)
cli wp plugin install "wp-content/navexa-dist/$name" --activate
# wp-env names the plugin's directory after the zip (plugin-check, or plugin-check.latest-stable).
pc_slug=$(cli wp plugin list --field=name | tr -d '\r' | grep -E '^plugin-check' | head -n 1 || true)
[ -n "$pc_slug" ] || die "Plugin Check is not installed"
pc_version=$(cli wp plugin get "$pc_slug" --field=version | tr -d '\r' | grep -xE '[0-9][0-9A-Za-z.-]*' | tail -n 1 || true)
echo "plugin-check: Plugin Check ${pc_version:-unknown} ($pc_slug)"

out="$tmp/check.txt"
if ! cli wp plugin check "$slug" --format=json > "$out" 2>&1 || ! grep -q '^\[' "$out"; then
	cli wp plugin check "$slug" --format=json "--require=./wp-content/plugins/$pc_slug/cli.php" > "$out" 2>&1 || true
fi
grep -q 'No errors found\|^\[' "$out" || { cat "$out"; die "Plugin Check gave no result"; }

# --format=json prints "FILE: <path>" and a JSON array of findings per file.
summary=$(python3 -I - "$out" << 'PY'
import json
import sys

errors = warnings = 0
current = ""
for line in open(sys.argv[1], encoding="utf-8", errors="replace"):
    line = line.strip()
    if line.startswith("FILE:"):
        current = line[5:].strip()
        continue
    if not line.startswith("["):
        continue
    for item in json.loads(line):
        kind = str(item.get("type", "")).upper()
        if kind == "ERROR":
            errors += 1
        else:
            warnings += 1
        print(f"{kind:7} {current}:{item.get('line', '?')} {item.get('code', '')} {item.get('message', '')}")
print(f"SUMMARY {errors} {warnings}")
PY
)
echo "$summary" | grep -v '^SUMMARY' || true
read -r _ errors warnings <<< "$(echo "$summary" | grep '^SUMMARY' | tail -n 1)"
echo "plugin-check: $errors error(s), $warnings warning(s)"
[ "$errors" = 0 ] || die "Plugin Check found $errors error(s)"
echo "plugin-check: ok: $name has no Plugin Check errors"
