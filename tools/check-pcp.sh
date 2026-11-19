#!/usr/bin/env bash
set -uo pipefail

# Runs Plugin Check against the PACKAGED tree, because the package is what
# WordPress.org reviews and it is not the source tree.
#
# Three things this gate does that a bare `plugin check` does not:
#
#   1. Verifies the staged copy landed. A gate elsewhere in this workspace once
#      reported "clean" after its copy had silently failed, which is the same
#      output as a clean run.
#   2. Compares the SET of warning codes against the baseline below, not the
#      count. This plugin owns custom tables, so it will always warn about
#      direct queries; what matters is whether a NEW KIND of warning appears.
#   3. Negative-controls itself on every run, by checking a deliberately broken
#      copy and requiring that it fails. A gate that cannot fail reports the
#      same thing whether or not it ran.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# No default, deliberately. The install to check against belongs to whoever is
# running this, so baking one in publishes a developer's path AND, on any other
# machine, points the gate at a directory that is not there - which is the
# failure mode the header above warns about, in the script that warns about it.
harness="${VITALLENS_HARNESS:-}"

if [ -z "$harness" ] || [ ! -f "$harness/wp-cli.phar" ]; then
	echo "Set VITALLENS_HARNESS to a WordPress install with wp-cli.phar and the plugin-check plugin active." >&2
	exit 2
fi
slug="vitallens"

# Every warning code this plugin is expected to trip, all of them the price of
# owning its own tables. Documented rather than suppressed: the queries are
# reviewed, and hiding the warnings would hide a new one too.
baseline="PluginCheck.Security.DirectDB.UnescapedDBParameter
WordPress.DB.DirectDatabaseQuery.DirectQuery
WordPress.DB.DirectDatabaseQuery.NoCaching
WordPress.DB.PreparedSQL.InterpolatedNotPrepared
WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare"

cd "$root"
bash tools/package.sh >/dev/null || { echo "FAIL  packaging"; exit 1; }

zip="$(ls -1t "$root"/dist/${slug}.*.zip 2>/dev/null | head -1)"
[ -f "$zip" ] || { echo "FAIL  no package produced"; exit 1; }

staging="$(mktemp -d)"
unzip -qo "$zip" -d "$staging" || { echo "FAIL  could not unpack $zip"; exit 1; }

# Run Plugin Check on a copy staged into the harness.
#
# $1 target directory name, $2 label
run_check() {
	local target="$harness/wp-content/plugins/$1"
	rm -rf "$target"
	cp -r "$staging/$slug" "$target" || { echo "FAIL  could not copy $2 into the harness"; return 1; }

	local count
	count="$(find "$target" -type f | wc -l | tr -d ' ')"
	if [ "$count" -lt 10 ]; then
		echo "FAIL  only $count files reached the harness for $2; the copy did not land"
		rm -rf "$target"
		return 1
	fi

	if [ "$2" = "control" ]; then
		# Blank the Stable tag, which Plugin Check reports as an ERROR. Any
		# deliberate break would do; this one touches no PHP.
		sed -i '' 's/^Stable tag:.*/Stable tag:/' "$target/readme.txt"
	fi

	( cd "$harness" && php wp-cli.phar plugin check "$1" --slug="$slug" --include-experimental --severity=0 --allow-root 2>&1 | grep -vi deprecated )
	rm -rf "$target"
}

status=0

out="$(run_check "${slug}-pcp" real)" || status=1
printf '  %s files staged from %s\n' "$(unzip -Z1 "$zip" | grep -vc '/$')" "$(basename "$zip")"

errors="$(printf '%s\n' "$out" | awk -F'\t' '$3 == "ERROR"')"
if [ -n "$errors" ]; then
	echo "FAIL  Plugin Check errors:"
	printf '%s\n' "$errors"
	status=1
else
	echo "  Plugin Check: 0 errors"
fi

codes="$(printf '%s\n' "$out" | awk -F'\t' '$3 == "WARNING" { print $4 }' | sort -u)"
total="$(printf '%s\n' "$out" | awk -F'\t' '$3 == "WARNING"' | wc -l | tr -d ' ')"
new="$(comm -23 <(printf '%s\n' "$codes") <(printf '%s\n' "$baseline" | sort))"

if [ -n "$new" ]; then
	echo "FAIL  warning codes not in the baseline:"
	printf '    %s\n' $new
	status=1
else
	printf '  Plugin Check: %s warnings, all %s known database codes\n' "$total" "$(printf '%s\n' "$codes" | wc -l | tr -d ' ')"
fi

# The gate must be able to fail, or a pass means nothing.
control="$(run_check "${slug}-pcp-control" control)"
if printf '%s\n' "$control" | awk -F'\t' '$3 == "ERROR"' | grep -q 'no_stable_tag'; then
	echo "  negative control: a blanked Stable tag was reported, so this gate can fail"
else
	echo "FAIL  negative control did not fail - this gate is not checking anything"
	status=1
fi

rm -rf "$staging"
exit "$status"
