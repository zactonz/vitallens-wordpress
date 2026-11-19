#!/usr/bin/env bash
set -euo pipefail

# Builds the distributable zip, reproducibly: the same commit gives the same
# bytes and therefore the same digest, so an artifact handed to QA can be
# verified as the one that was reviewed.
#
# Reproducibility takes BOTH lines below, and neither alone is enough:
#
#   touch -t  freezes the DOS timestamps, DIRECTORIES included - they are made
#             by mkdir and otherwise take the clock.
#   zip -X    drops the extended-timestamp extra field, which carries the ACCESS
#             time. zip reads every file to compress it, so the access time it
#             would record is the moment of the build whatever was set first.
#
# touch -r is wrong here for the same reason -X is needed: it copies the
# reference file's access time, and that moves every time anything reads it.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

name="$(sed -n 's/^ \* Plugin Name:[[:space:]]*//p' vitallens.php | head -1 | tr -d '\r')"
slug="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-//; s/-$//')"
dir_slug="$(basename "$root")"

# WordPress.org derives the slug from the Plugin Name header, not the folder, so
# a mismatch here means the directory would install the plugin under a different
# name than every path and option in it assumes.
if [ "$slug" != "$dir_slug" ]; then
	printf 'slug mismatch: Plugin Name "%s" derives "%s" but the directory is "%s"\n' "$name" "$slug" "$dir_slug" >&2
	exit 1
fi

domain="$(sed -n 's/^ \* Text Domain:[[:space:]]*//p' vitallens.php | head -1 | tr -d '\r')"
if [ "$domain" != "$slug" ]; then
	printf 'text domain "%s" does not match slug "%s"\n' "$domain" "$slug" >&2
	exit 1
fi

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' vitallens.php | head -1 | tr -d '\r')"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1 | tr -d '\r')"

if [ "$version" != "$stable" ]; then
	printf 'Version header "%s" and Stable tag "%s" disagree\n' "$version" "$stable" >&2
	exit 1
fi

# One source of truth for what ships. Reading .distignore rather than repeating
# it here means a file excluded from the package cannot be included by the build.
excludes=()
while IFS= read -r line; do
	case "$line" in
		''|'#'*) continue ;;
	esac
	excludes+=( --exclude "$line" )
done < .distignore

dist="$root/dist"
rm -rf "$dist"
mkdir -p "$dist/$slug"

rsync -a --prune-empty-dirs "${excludes[@]}" ./ "$dist/$slug/"

stamp="$(git -C "$root" log -1 --format=%cd --date=format:'%Y%m%d%H%M.%S' 2>/dev/null || true)"

if [ -z "$stamp" ]; then
	# An exported tree with no git. The plugin file's mtime still gives one
	# stamp for one state of the source.
	stamp="$(stat -f '%Sm' -t '%Y%m%d%H%M.%S' "$root/vitallens.php")"
fi

find "$dist/$slug" -exec touch -t "$stamp" {} +

( cd "$dist" && zip -qrX "$slug.$version.zip" "$slug" )
( cd "$dist" && shasum -a 256 "$slug.$version.zip" > "$slug.$version.zip.sha256" )

# Nothing hidden: Plugin Check refuses a package containing one, and .DS_Store
# appears in any folder someone has opened in Finder.
hidden="$(find "$dist/$slug" -name '.*' -not -name '.' | head -5)"
if [ -n "$hidden" ]; then
	printf 'hidden files reached the package:\n%s\n' "$hidden" >&2
	exit 1
fi

printf 'packaged %s %s (%s)\n' "$slug" "$version" "$(du -h "$dist/$slug.$version.zip" | cut -f1)"
printf 'files: %s\n' "$(find "$dist/$slug" -type f | wc -l | tr -d ' ')"
printf 'sha256: %s\n' "$(cut -d' ' -f1 "$dist/$slug.$version.zip.sha256")"
