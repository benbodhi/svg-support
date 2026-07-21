#!/usr/bin/env bash
#
# Run the WordPress.org "Plugin Check" against this plugin inside your LocalWP
# test site — no manual copying and no globally-installed wp-cli required.
#
# It reuses LocalWP's bundled wp-cli + PHP and talks to the site's running
# MySQL socket, so it checks exactly the code in this repo (the plugin folder
# in LocalWP is a symlink back to here).
#
# Usage:
#   bin/plugin-check.sh                      # check the whole plugin
#   bin/plugin-check.sh --ignore-warnings    # errors only
#   bin/plugin-check.sh --checks=i18n_usage  # any extra flags pass straight to wp plugin check
#
# Override the target site / slug via env:
#   LOCAL_SITE=mysite PLUGIN_SLUG=svg-support bin/plugin-check.sh
#
# Exit codes:
#   0  ran successfully (see report for warnings/errors)
#   2  LocalWP site is not running (DB socket missing) — check skipped
#   1  setup problem (Local not installed, slug missing, etc.)
#
set -uo pipefail

LOCAL_SITE="${LOCAL_SITE:-wpnightly}"
PLUGIN_SLUG="${PLUGIN_SLUG:-svg-support}"

LOCAL_SUPPORT="$HOME/Library/Application Support/Local"
SITES_JSON="$LOCAL_SUPPORT/sites.json"
LIGHTNING="$LOCAL_SUPPORT/lightning-services"
WPCLI="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar"

die() { echo "❌ $*" >&2; exit 1; }

[[ -f "$SITES_JSON" ]] || die "LocalWP sites.json not found ($SITES_JSON). Is Local installed?"
[[ -f "$WPCLI" ]]      || die "Bundled wp-cli not found ($WPCLI)."

# A php binary just to parse sites.json (any LocalWP php will do).
boot_php="$(/usr/bin/find "$LIGHTNING" -maxdepth 6 -type f -name php 2>/dev/null | sort | tail -1)"
[[ -n "$boot_php" ]] || die "No LocalWP php binary found under $LIGHTNING."

# Resolve the site's id, path and php version by name.
read -r SITE_ID SITE_PATH SITE_PHP < <("$boot_php" -r '
  $name  = $argv[1];
  $sites = json_decode(file_get_contents($argv[2]), true) ?: [];
  foreach ($sites as $s) {
    if (($s["name"] ?? "") === $name) {
      echo $s["id"]." ".$s["path"]." ".($s["services"]["php"]["version"] ?? "")."\n";
      exit;
    }
  }
  exit(1);
' "$LOCAL_SITE" "$SITES_JSON")

[[ -n "${SITE_ID:-}" ]] || die "Site \"$LOCAL_SITE\" not found in LocalWP. Set LOCAL_SITE to the right name."

SITE_PUBLIC="$SITE_PATH/app/public"
SOCK="$LOCAL_SUPPORT/run/$SITE_ID/mysql/mysqld.sock"

# Prefer the php that matches the site; fall back to the bootstrap php.
PHP="$(/usr/bin/find "$LIGHTNING/php-$SITE_PHP"* -maxdepth 4 -type f -name php 2>/dev/null | sort | tail -1)"
[[ -n "$PHP" ]] || PHP="$boot_php"

# The DB socket only exists while the site is running.
if [[ ! -S "$SOCK" ]]; then
  echo "⚠️  LocalWP site \"$LOCAL_SITE\" isn't running (no DB socket). Start it in Local, then re-run." >&2
  exit 2
fi

# Mirror .distignore so we only report on files that actually ship.
EXCLUDE_DIRS=".git,.github,.wordpress-org,node_modules,bin"
EXCLUDE_FILES=".DS_Store,.gitignore,.gitattributes,.distignore,README.md,LICENSE,composer.json,composer.lock"

echo "▶ Plugin Check: $PLUGIN_SLUG  (site: $LOCAL_SITE, php ${SITE_PHP:-?})"

# Run the check. Keep stderr separate so we can strip the harmless deprecation
# notices the bundled wp-cli emits on newer PHP, while preserving real errors.
err="$(mktemp)"
"$PHP" \
  -d error_reporting="E_ALL & ~E_DEPRECATED" \
  -d mysqli.default_socket="$SOCK" \
  -d pdo_mysql.default_socket="$SOCK" \
  "$WPCLI" --path="$SITE_PUBLIC" plugin check "$PLUGIN_SLUG" \
    --exclude-directories="$EXCLUDE_DIRS" \
    --exclude-files="$EXCLUDE_FILES" \
    "$@" \
  2>"$err"
rc=$?

grep -v -e 'Deprecated:' -e 'php-cli-tools' -e 'react/promise' "$err" >&2 || true
rm -f "$err"

exit "$rc"
