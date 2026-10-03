#!/usr/bin/env bash
# Rebuild the private PHP 7.4 WHATWG URL dependency bundle.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$(mktemp -d /tmp/opf-url-vendor.XXXXXX)"
trap 'rm -rf "$BUILD"' EXIT
mkdir -p "$BUILD/input"
composer require --working-dir="$BUILD" --no-interaction \
  rowbot/url:3.1.7 rowbot/idna:0.1.5 rowbot/punycode:1.0.4 \
  brick/math:0.9.3 symfony/polyfill-mbstring:1.31.0 \
  symfony/polyfill-intl-normalizer:1.31.0 humbug/php-scoper:0.18.18
for package in rowbot/url rowbot/idna rowbot/punycode brick/math; do
  mkdir -p "$BUILD/input/$package"
  cp -a "$BUILD/vendor/$package/src" "$BUILD/input/$package/"
  for extra in resources LICENSE LICENSE.md LICENSE.txt; do
    if [ -e "$BUILD/vendor/$package/$extra" ]; then
      cp -a "$BUILD/vendor/$package/$extra" "$BUILD/input/$package/"
    fi
  done
done
for package in symfony/polyfill-mbstring symfony/polyfill-intl-normalizer; do
  mkdir -p "$BUILD/input/$package"
  cp -a "$BUILD/vendor/$package/LICENSE" "$BUILD/input/$package/"
  cp -a "$BUILD/vendor/$package/Resources" "$BUILD/input/$package/"
done
cp "$BUILD/vendor/symfony/polyfill-mbstring/Mbstring.php" "$BUILD/input/symfony/polyfill-mbstring/"
cp "$BUILD/vendor/symfony/polyfill-intl-normalizer/Normalizer.php" "$BUILD/input/symfony/polyfill-intl-normalizer/"
# Scoped private Normalizer is supplied by our loader, so no global stubs.
rm -rf "$BUILD/input/symfony/polyfill-intl-normalizer/Resources/stubs"
"$BUILD/vendor/bin/php-scoper" add-prefix "$BUILD/input" \
  --config="$ROOT/bin/url-vendor-scoper.php" --output-dir="$BUILD/output" --php-version=7.4 --no-interaction
mkdir -p "$ROOT/includes/ThirdParty/Url"
cp -a "$BUILD/output/." "$ROOT/includes/ThirdParty/Url/"
