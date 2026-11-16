#!/usr/bin/env bash
# Builds the distributable zip of Navexa AgeCheck for WooCommerce and its
# SHA256SUMS (milestone W5, PR 17).
#
#   bin/build-zip.sh [--ref <commit-ish> | --worktree] [--tag vX.Y.Z] [--out <dir>] [--check]
#
# --ref       build from a commit (default HEAD), through `git archive`, so
#             .gitattributes export-ignore decides what is left out; what is
#             not committed is not in the zip
# --worktree  build from the working tree instead (tracked and untracked
#             files git does not ignore, minus export-ignore): a dry run
#             before committing; never for a release
# --tag       also require the plugin header Version, NAVEXA_AGECHECK_VERSION
#             and readme.txt's Stable tag to equal the tag without its "v"
# --out       output directory (default dist/ in the repository; git-ignored)
# --check     run the version and content checks only; write nothing
#
# Always checked, on the files that go into the zip: the plugin header
# Version, NAVEXA_AGECHECK_VERSION and readme.txt's Stable tag are one
# version with a "= version =" changelog entry; the zip holds only runtime
# files (the plugin's PHP, assets/ with every minified script's unminified
# build and the sources in assets/src/ for wordpress.org Guideline 4,
# readme.txt, languages/ with the .pot, LICENSE) and nothing of the
# development setup (tests, node_modules, vendor, Composer and npm files,
# tool configuration, dotfiles).
#
# Reproducible: entries sorted, every timestamp the commit's time, fixed
# permissions, no extra fields, deflate level 9. The same commit gives the
# same bytes with the same Python/zlib (release.yml builds twice and
# compares).
#
# Output: <out>/navexa-agecheck-for-woocommerce-<version>.zip and
# <out>/SHA256SUMS (sha256sum format: `sha256sum -c SHA256SUMS`). Under
# GitHub Actions also version=, zip= and sha256= in $GITHUB_OUTPUT.
set -euo pipefail

slug=navexa-agecheck-for-woocommerce
root=$(git -C "$(dirname "$0")/.." rev-parse --show-toplevel)
ref=HEAD
worktree=0
tag=''
out="$root/dist"
check_only=0

die() {
	echo "build-zip: $*" >&2
	exit 1
}

usage() {
	sed -n '2,/^set -euo/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
	case "$1" in
		--ref) ref=${2:?--ref needs a commit}; shift 2 ;;
		--worktree) worktree=1; shift ;;
		--tag) tag=${2:?--tag needs a tag}; shift 2 ;;
		--out) out=${2:?--out needs a directory}; shift 2 ;;
		--check) check_only=1; shift ;;
		-h | --help) usage; exit 0 ;;
		*) usage >&2; die "unknown argument: $1" ;;
	esac
done

command -v python3 > /dev/null || die "python3 is needed to write the zip"
commit=$(git -C "$root" rev-parse --verify --quiet "$ref^{commit}") || die "not a commit: $ref"
[ "$worktree" = 1 ] && commit=$(git -C "$root" rev-parse --verify HEAD)
epoch=$(git -C "$root" log -1 --format=%ct "$commit")

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
dest="$stage/$slug"
mkdir -p "$dest"

# Paths `git archive` would write from the working tree: what git tracks or
# would track, as it is on disk, minus export-ignore. A directory's
# attributes do not reach the files inside it (git archive skips the whole
# tree), so every parent directory is asked about too.
worktree_files() {
	local f p skip
	local -a files=()
	local -A ignored=()
	while IFS= read -r -d '' f; do
		[ -f "$root/$f" ] && [ ! -L "$root/$f" ] && files+=("$f")
	done < <(git -C "$root" ls-files -z --cached --others --exclude-standard)
	while IFS= read -r -d '' p && IFS= read -r -d '' _ && IFS= read -r -d '' value; do
		[ "$value" = set ] && ignored["$p"]=1
	done < <(
		for f in "${files[@]}"; do
			p=$f
			printf '%s\0' "$p"
			while [ "${p%/*}" != "$p" ]; do
				p=${p%/*}
				printf '%s\0' "$p"
			done
		done | sort -zu | git -C "$root" check-attr -z --stdin export-ignore
	)
	for f in "${files[@]}"; do
		p=$f
		skip=0
		while :; do
			if [ -n "${ignored[$p]:-}" ]; then
				skip=1
				break
			fi
			[ "${p%/*}" = "$p" ] && break
			p=${p%/*}
		done
		[ "$skip" = 1 ] || printf '%s\0' "$f"
	done
}

if [ "$worktree" = 1 ]; then
	worktree_files | tar -C "$root" --null -T - -cf - | tar -C "$dest" -xf -
else
	git -C "$root" archive --format=tar "$commit" | tar -C "$dest" -xf -
fi

# --- Versions -----------------------------------------------------------------

main="$dest/$slug.php"
readme="$dest/readme.txt"
[ -f "$main" ] || die "$slug.php is missing"
[ -f "$readme" ] || die "readme.txt is missing"
header=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$main" | head -n 1)
constant=$(sed -n "s/^define( 'NAVEXA_AGECHECK_VERSION', '\([^']*\)' );.*/\1/p" "$main" | head -n 1)
stable=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$readme" | head -n 1)
[ -n "$header" ] || die "no Version: in the $slug.php header"
[ -n "$constant" ] || die "no NAVEXA_AGECHECK_VERSION define in $slug.php"
[ -n "$stable" ] || die "no Stable tag: in readme.txt"
[ "$header" = "$constant" ] || die "the plugin header says Version $header, NAVEXA_AGECHECK_VERSION says $constant"
[ "$header" = "$stable" ] || die "the plugin header says Version $header, readme.txt says Stable tag $stable"
version=$header
[[ "$version" =~ ^[0-9]+(\.[0-9]+){1,3}(-[0-9A-Za-z.]+)?$ ]] || die "not a release version: $version"
# Only the Changelog section counts: == Upgrade Notice == repeats the heading.
sed -n '/^== Changelog ==/,/^== /p' "$readme" | grep -qxF "= $version =" || die "readme.txt's == Changelog == has no '= $version =' entry"
if [ -n "$tag" ]; then
	[[ "$tag" =~ ^v[0-9] ]] || die "a release tag is v<version>, not $tag"
	[ "${tag#v}" = "$version" ] || die "tag $tag does not match the plugin header Version and readme.txt Stable tag ($version)"
fi
pot="$dest/languages/$slug.pot"
if [ -f "$pot" ] && ! grep -qF "\"Project-Id-Version: Navexa AgeCheck for WooCommerce $version\\n\"" "$pot"; then
	echo "build-zip: warning: languages/$slug.pot was made for another version (regenerate it, README.md)" >&2
fi

# --- Contents -----------------------------------------------------------------

for entry in "$dest"/* "$dest"/.[!.]* "$dest"/..?*; do
	[ -e "$entry" ] || [ -L "$entry" ] || continue
	case "${entry#"$dest"/}" in
		"$slug.php" | uninstall.php | readme.txt | LICENSE | includes | assets | languages) ;;
		*) die "not a runtime file: ${entry#"$dest"/} (add it to .gitattributes export-ignore, or to this allowlist if the plugin needs it)" ;;
	esac
done
for required in uninstall.php LICENSE includes/Plugin.php "languages/$slug.pot" assets/src/loader/agecheck.ts assets/src/loader/build.mjs assets/src/blocks; do
	[ -e "$dest/$required" ] || die "$required is missing"
done
ls "$dest"/assets/js/agecheck-*.min.js > /dev/null 2>&1 || die "the built loader (assets/js/agecheck-*.min.js) is missing"
ls "$dest"/assets/build/*/block.json > /dev/null 2>&1 || die "the built checkout block (assets/build/) is missing"
while IFS= read -r -d '' min; do
	[ -f "${min%.min.js}.js" ] || die "${min#"$dest"/} has no unminified build next to it (Guideline 4)"
done < <(find "$dest/assets" -name '*.min.js' -print0)
unwanted=$(cd "$dest" && find . \( -name '.*' -o -name node_modules -o -name vendor -o -name tests -o -name '*.test.*' -o -name '*.map' \
	-o -name 'composer.*' -o -name 'package*.json' -o -name 'phpcs.xml*' -o -name 'phpstan.neon*' -o -name 'phpunit.xml*' -o -name 'tsconfig*.json' \
	-o -name 'webpack.config.*' -o -name '*.md' -o -type l \) ! -name . -print | sort)
[ -z "$unwanted" ] || die "development files would ship: $(echo "$unwanted" | tr '\n' ' ')"
stray=$(cd "$dest" && find . -name '*.php' ! -path "./$slug.php" ! -path ./uninstall.php ! -path './includes/*' ! -path './assets/build/*.asset.php' -print | sort)
[ -z "$stray" ] || die "PHP outside includes/: $(echo "$stray" | tr '\n' ' ')"

count=$(find "$dest" -type f | wc -l | tr -d ' ')
if [ "$check_only" = 1 ]; then
	echo "build-zip: ok: version $version${tag:+ (tag $tag)}, $count files from $([ "$worktree" = 1 ] && echo 'the working tree' || echo "${commit:0:12}")"
	exit 0
fi

# --- Zip ----------------------------------------------------------------------

mkdir -p "$out"
out=$(cd "$out" && pwd)
name="$slug-$version.zip"
zip="$out/$name"
rm -f "$zip" "$out/SHA256SUMS"
python3 -I - "$stage" "$slug" "$zip" "$epoch" << 'PY'
import os
import sys
import time
import zipfile

stage, slug, target, epoch = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
# A zip's DOS timestamps start in 1980.
stamp = time.gmtime(max(epoch, 315532800))[:6]
entries = []
for dirpath, dirnames, filenames in os.walk(os.path.join(stage, slug)):
    dirnames.sort()
    rel = os.path.relpath(dirpath, stage).replace(os.sep, "/")
    entries.append((rel + "/", None))
    entries.extend((rel + "/" + name, os.path.join(dirpath, name)) for name in filenames)
entries.sort(key=lambda entry: entry[0])
with zipfile.ZipFile(target, "w") as archive:
    for arcname, path in entries:
        info = zipfile.ZipInfo(arcname, stamp)
        info.create_system = 3
        if path is None:
            info.external_attr = (0o040755 << 16) | 0x10
            archive.writestr(info, b"")
            continue
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        with open(path, "rb") as handle:
            archive.writestr(info, handle.read(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
PY

if command -v sha256sum > /dev/null; then
	(cd "$out" && sha256sum "$name" > SHA256SUMS)
else
	(cd "$out" && shasum -a 256 "$name" > SHA256SUMS)
fi
sum=$(cut -d ' ' -f 1 "$out/SHA256SUMS")
echo "build-zip: $zip"
echo "build-zip: version $version, $count files, $(wc -c < "$zip" | tr -d ' ') bytes, sha256 $sum, from $([ "$worktree" = 1 ] && echo 'the working tree (not for release)' || echo "commit ${commit:0:12}")"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{
		echo "version=$version"
		echo "zip=$zip"
		echo "sha256=$sum"
	} >> "$GITHUB_OUTPUT"
fi
