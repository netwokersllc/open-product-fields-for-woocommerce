#!/usr/bin/env bash
# =============================================================================
# Open Product Fields — full test suite runner.
#
# Usage:
#   bin/run-all-tests.sh <test-site-path> <wapf-export.json> [iterations]
#
# Prerequisites:
#   - Disposable WordPress install (see docs/MIGRATION.md Phase 1) with
#     WooCommerce + Open Product Fields active
#   - wp-cli available; jsdom installed where bin/e2e-jsdom-test.mjs runs
#     (cd <jsdom-dir> && npm i jsdom)
#   - PHP built-in server reachable (started by this script)
#
# Every iteration runs, in order:
#   1. Lint (PHP + JS syntax)
#   2. Unit tests (PHPUnit)
#   3. CLI E2E (full lifecycle incl. Store API checkout)
#   4. Real-HTTP product page + add-to-cart + Store API cart verification
#   5. jsdom behavior test (real module, real page HTML, real events)
#   6. Import verification (fixture reset + real WAPF corpus, commit mode)
#   7. Activation-cycle + uninstall cleanliness (runs last: deletes groups)
# =============================================================================
set -u

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SITE_DIR="${1:-$HOME/opf-test/wordpress}"
EXPORT="${2:-/tmp/wapf-export.json}"
ITERATIONS="${3:-1}"
HTTP="http://127.0.0.1:8090"
JSDOM_DIR="${JSDOM_DIR:-/tmp/jsdom-test}"

FAIL=0
run() { # run <label> <expected-ok-count> <command...>
	local label="$1" expect="$2"; shift 2
	local out
	out=$("$@" 2>&1 | grep -v Warning)
	local ok
	ok=$(echo "$out" | grep -cE "^  ok" || true)
	if echo "$out" | grep -qE "^  FAIL|check\(s\) failed|^Error:" ; then
		echo "  SUITE FAIL: $label"
		echo "$out" | grep -E "^  FAIL|check\(s\) failed|^Error:" | head -5
		FAIL=$((FAIL+1))
	elif [ -n "$expect" ] && [ "$ok" -lt "$expect" ]; then
		echo "  SUITE FAIL: $label (ok=$ok expected>=$expect)"
		FAIL=$((FAIL+1))
	else
		echo "  suite ok: $label ($ok checks)"
	fi
}

echo "== OPF full test suite — $ITERATIONS iteration(s) =="

for i in $(seq 1 "$ITERATIONS"); do
	echo "--- iteration $i ---"

	# 1. Lint
	LINT_BAD=0
	while IFS= read -r f; do php -l "$f" > /dev/null 2>&1 || { echo "  LINT FAIL: $f"; LINT_BAD=1; }; done \
		< <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/.git/*' -not -path '*/vendor/*')
	node --check "$PLUGIN_DIR/assets/js/opf-frontend.js" || LINT_BAD=1
	node --check "$PLUGIN_DIR/assets/js/opf-builder.js" || LINT_BAD=1
	if [ "$LINT_BAD" -ne 0 ]; then FAIL=$((FAIL+1)); else echo "  suite ok: lint"; fi

	# 2. Unit
	( cd "$PLUGIN_DIR" && vendor/bin/phpunit > /tmp/opf-phpunit.out 2>&1 ) \
		&& echo "  suite ok: unit ($(grep -oE 'OK \([0-9]+ tests, [0-9]+ assertions\)' /tmp/opf-phpunit.out))" \
		|| { echo "  SUITE FAIL: unit"; tail -5 /tmp/opf-phpunit.out; FAIL=$((FAIL+1)); }

	# 3. CLI E2E
	run "cli-e2e" 39 wp eval-file "$PLUGIN_DIR/bin/e2e-test.php" --path="$SITE_DIR"

	# 4. Real HTTP: page markers, add-to-cart, Store API cart
	GID=$(wp post list --post_type=opf_field_group --name=e2e-group --field=ID --path="$SITE_DIR" 2>/dev/null | head -1)
	PID=$(wp post list --post_type=product --name=e2e-matched-product --field=ID --path="$SITE_DIR" 2>/dev/null | head -1)
	JAR=$(mktemp)
	curl -s "$HTTP/product/e2e-matched-product/" -o /tmp/opf-http-page.html
	PAGE_OK=1
	for m in opf-field wapf-field-container data-wapf-price OPF_FIELDS wapf_config; do
		grep -q "$m" /tmp/opf-http-page.html || { PAGE_OK=0; echo "  HTTP FAIL: missing $m"; }
	done
	[ "$PAGE_OK" -eq 1 ] && echo "  suite ok: http-page"
	curl -s -c "$JAR" -o /dev/null "$HTTP/product/e2e-matched-product/" \
		--data-urlencode "add-to-cart=$PID" \
		--data-urlencode "quantity=1" \
		--data-urlencode "opf[$GID][delivery]=boost"
	CART=$(curl -s -b "$JAR" "$HTTP/wp-json/wc/store/v1/cart")
	echo "$CART" | grep -q '"price":"10500"' && echo "$CART" | grep -q 'Delivery speed' \
		&& echo "  suite ok: http-cart (10500 + label)" \
		|| { echo "  SUITE FAIL: http-cart"; echo "$CART" | head -c 400; FAIL=$((FAIL+1)); }
	rm -f "$JAR"

	# 5. jsdom behavior (fresh page each iteration)
	curl -s "$HTTP/product/e2e-matched-product/" -o /tmp/opf-http-page.html
	( cd "$JSDOM_DIR" && node "$PLUGIN_DIR/bin/e2e-jsdom-test.mjs" > /tmp/opf-jsdom.out 2>&1 ) \
		&& echo "  suite ok: jsdom ($(grep -cE '^  ok' /tmp/opf-jsdom.out) checks)" \
		|| { echo "  SUITE FAIL: jsdom"; tail -5 /tmp/opf-jsdom.out; FAIL=$((FAIL+1)); }

	# 5b. Theme quantity.js compat (verbatim legacy math over OPF markup)
	( cd "$JSDOM_DIR" && node "$PLUGIN_DIR/bin/e2e-theme-compat-test.mjs" > /tmp/opf-themecompat.out 2>&1 ) \
		&& echo "  suite ok: theme-compat ($(grep -cE '^  ok' /tmp/opf-themecompat.out) checks)" \
		|| { echo "  SUITE FAIL: theme-compat"; tail -5 /tmp/opf-themecompat.out; FAIL=$((FAIL+1)); }

	# 5c. Full browser UI flow (optional — requires playwright + chromium)
	if [ -d "$JSDOM_DIR/node_modules/playwright" ]; then
		( cd "$JSDOM_DIR" && OPF_BASE_URL="$HTTP" node "$PLUGIN_DIR/bin/e2e-browser-test.mjs" > /tmp/opf-browser.out 2>&1 ) \
			&& echo "  suite ok: browser-ui ($(grep -cE '^  ok' /tmp/opf-browser.out) checks)" \
			|| { echo "  SUITE FAIL: browser-ui"; tail -5 /tmp/opf-browser.out; FAIL=$((FAIL+1)); }
	else
		echo "  suite skip: browser-ui (playwright not installed in $JSDOM_DIR)"
	fi

	# 6. Import verification — reset OPF groups for a clean-state import each time
	wp eval 'foreach (get_posts(["post_type"=>"opf_field_group","post_status"=>"any","posts_per_page"=>-1,"fields"=>"ids"]) as $id) { wp_delete_post($id, true); } OPF\Service\FieldGroups::flush_cache();' --path="$SITE_DIR" > /dev/null 2>&1
	run "import-verify" 9 wp eval-file "$PLUGIN_DIR/bin/e2e-import-test.php" "$EXPORT" --path="$SITE_DIR"

	# 7. Uninstall + activation cycle (deletes groups — always last)
	run "uninstall-cycle" 8 wp eval-file "$PLUGIN_DIR/bin/e2e-uninstall-test.php" --path="$SITE_DIR"
done

echo "== result =="
if [ "$FAIL" -eq 0 ]; then
	echo "ALL SUITES PASSED ($ITERATIONS iteration(s))"
	exit 0
else
	echo "$FAIL SUITE FAILURE(S)"
	exit 1
fi
