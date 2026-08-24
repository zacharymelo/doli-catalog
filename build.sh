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

# Dolibarr's deployer validates the filename against
#   /^(module[a-zA-Z0-9]*_|theme_|).*-([0-9][0-9.]*)(\s\(\d+\)\s)?\.zip$/i
# and derives the module name by stripping that same version segment. The part
# before .zip must therefore be digits and dots only: a filename carrying a
# pre-release suffix is refused outright with "filename does not match Dolibarr
# package rules", so it cannot even be uploaded to test.
#
# The descriptor keeps the full version, which is what Dolibarr shows in the
# module list; only the filename is reduced.
ZIPVERSION="$(printf '%s' "$VERSION" | sed -E 's/^([0-9][0-9.]*).*/\1/')"

if [ -z "$ZIPVERSION" ]; then
	echo "build.sh: version '$VERSION' has no numeric part to name the zip with" >&2
	exit 1
fi

if [ "$ZIPVERSION" != "$VERSION" ]; then
	echo "build.sh: $VERSION is a pre-release; packaging as $ZIPVERSION so Dolibarr will accept the upload" >&2
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$MODULE"
cp -R "$ROOT/module/." "$STAGE/$MODULE/"

# Never ship development leftovers.
find "$STAGE/$MODULE" -name '.DS_Store' -delete

OUT="$ROOT/$MODULE-$ZIPVERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && zip -rq "$OUT" "$MODULE")

echo "Built $OUT"
