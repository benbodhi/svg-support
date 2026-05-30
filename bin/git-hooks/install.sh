#!/usr/bin/env bash
#
# Install the repo's git hooks into .git/hooks (copies, so they actually run).
# Re-run this any time the hooks change or after a fresh clone.
#
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
src="$repo_root/bin/git-hooks"
dest="$repo_root/.git/hooks"

for hook in pre-push; do
  cp "$src/$hook" "$dest/$hook"
  chmod +x "$dest/$hook"
  echo "Installed $hook → .git/hooks/$hook"
done

# Remove the old pre-commit gate if a previous version installed it.
if [ -f "$dest/pre-commit" ] && grep -q 'plugin-check.sh' "$dest/pre-commit" 2>/dev/null; then
  rm -f "$dest/pre-commit"
  echo "Removed old pre-commit hook (gate moved to pre-push)."
fi
