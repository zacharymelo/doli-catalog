#!/usr/bin/env bash
# Build an installable Dolibarr module zip.
# The zip must have "dolicatalog/" at its top level, not "module/".
set -euo pipefail

MODULE=dolicatalog
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Accepts a pre-release suffix (1.6.0-dev) so a branch can be packaged for
# testing. `|| true` matters: under `set -e` a non-matching grep aborts the
# script before the check below can report why, which fails silently.
VERSION="$(grep -oE "this->version *= *'[^']+'" "$ROOT/module/core/modules/modDoliCatalog.class.php" \
	| head -1 | sed -E "s/.*'([^']+)'.*/\1/" || true)"

if [ -z "$VERSION" ]; then
	echo "build.sh: could not read \$this->version from the module descriptor" >&2
	exit 1
fi

case "$VERSION" in
	*-*) echo "build.sh: packaging pre-release $VERSION (not for production)" >&2 ;;
esac

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$MODULE"
cp -R "$ROOT/module/." "$STAGE/$MODULE/"

# Never ship development leftovers.
find "$STAGE/$MODULE" -name '.DS_Store' -delete

OUT="$ROOT/$MODULE-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && zip -rq "$OUT" "$MODULE")

echo "Built $OUT"
