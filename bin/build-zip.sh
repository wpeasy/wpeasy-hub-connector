#!/usr/bin/env bash
# Builds dist/wpeasy-hub-connector-<version>.zip, ready to upload at Plugins > Add New > Upload Plugin.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "^ \* Version:" wpeasy-hub-connector.php | sed 's/.*Version: *//')
rm -rf dist/build && mkdir -p dist/build/wpeasy-hub-connector
cp -R wpeasy-hub-connector.php uninstall.php readme.txt includes dist/build/wpeasy-hub-connector/
(cd dist/build && zip -qr "../wpeasy-hub-connector-${VERSION}.zip" wpeasy-hub-connector)
rm -rf dist/build
echo "dist/wpeasy-hub-connector-${VERSION}.zip"
