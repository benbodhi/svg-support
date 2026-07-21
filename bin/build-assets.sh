#!/usr/bin/env bash
#
# Minify the plugin's front-end/editor JavaScript into js/min/ using terser,
# fetched on demand via npx — no local install, config, or build app required.
#
# Run this after editing any of the source files below, then commit the
# regenerated js/min/ output alongside your change.
#
# Not minified on purpose:
#   - js/svgs-settings.js and css/*.css — admin-only or tiny; they ship
#     readable and gzip closes the gap.
#
# Usage:
#   bin/build-assets.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

command -v npx >/dev/null 2>&1 || { echo "❌ npx not found — install Node.js first." >&2; exit 1; }

SOURCES=(
	js/svgs-inline.js
	js/svgs-inline-vanilla.js
	js/gutenberg-filters.js
)

for src in "${SOURCES[@]}"; do
	out="js/min/$(basename "${src%.js}")-min.js"
	npx --yes terser@5 "$src" --compress --mangle --output "$out"
	echo "✓ $out ($(wc -c < "$out" | tr -d ' ') bytes)"
done
