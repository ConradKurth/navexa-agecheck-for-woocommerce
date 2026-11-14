// Writes .wp-env.override.json for one CI matrix leg:
//   node tests/wp-env/write-override.mjs <core zip URL or ""> <WooCommerce version> <PHP version>
import { writeFileSync } from "node:fs"

const [core, wc, php] = process.argv.slice(2)
if (!wc || !php) {
  console.error("usage: write-override.mjs <core|''> <woocommerce-version> <php-version>")
  process.exit(2)
}
const config = { phpVersion: php, plugins: [`https://downloads.wordpress.org/plugin/woocommerce.${wc}.zip`, "."] }
if (core) config.core = core
writeFileSync(".wp-env.override.json", JSON.stringify(config, null, 2) + "\n")
console.log(JSON.stringify(config))
