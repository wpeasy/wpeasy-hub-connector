#!/usr/bin/env bash
# Makes a release: checks, builds the zip, tags v<version> and creates a GitHub release with the zip attached (name: wpeasy-hub-connector-<version>.zip).
# Paired websites are offered the update by the Hub within about an hour. Needs the GitHub CLI (gh) signed in.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "^ \* Version:" wpeasy-hub-connector.php | sed 's/.*Version: *//')
CONST=$(grep -m1 "^const VERSION" wpeasy-hub-connector.php | sed "s/.*'\(.*\)'.*/\1/")
STABLE=$(grep -m1 "^Stable tag:" readme.txt | sed 's/.*: *//')
[ "$VERSION" = "$CONST" ] && [ "$VERSION" = "$STABLE" ] || { echo "Version mismatch: header $VERSION, constant $CONST, readme $STABLE"; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Commit your changes first."; exit 1; }
git rev-parse "v$VERSION" >/dev/null 2>&1 && { echo "v$VERSION already exists. Bump the version first."; exit 1; }
for f in wpeasy-hub-connector.php uninstall.php includes/*.php; do php -l "$f" >/dev/null; done
php tests/signature-test.php
ZIP=$(./bin/build-zip.sh)
git tag "v$VERSION" && git push origin main "v$VERSION"
gh release create "v$VERSION" "$ZIP" --title "v$VERSION" --notes "${1:-Release $VERSION}"
echo "Released v$VERSION"
