#!/usr/bin/env bash
set -euo pipefail

ROOT=/tmp/opf-quantity-fee-lifecycle-20261002
CLONE=/tmp/opf-quantity-fee-woo-20261002
MU_LINK="$CLONE/wp-content/mu-plugins/qfl-order-again.php"
MU_TARGET="$ROOT/bin/e2e-price-quantity-flat-order-again-mu.php"
SERVER_PID=
SETUP_STARTED=0

[[ "$PWD" == "$ROOT" ]] || { echo "Run from $ROOT" >&2; exit 2; }
[[ "$(realpath "$CLONE")" == "$CLONE" ]] || { echo "Clone path mismatch" >&2; exit 2; }
[[ "${OPF_QFL_PROOF_ALLOW:-}" == 1 ]] || { echo "Set OPF_QFL_PROOF_ALLOW=1 to run the disposable proof" >&2; exit 2; }
[[ ! -e "$MU_LINK" && ! -L "$MU_LINK" ]] || { echo "Refusing existing clone MU-plugin path: $MU_LINK" >&2; exit 2; }
if curl --max-time 1 -fsS http://127.0.0.1:8207/ >/dev/null 2>&1; then
	echo "Refusing occupied loopback port 8207" >&2
	exit 2
fi
if php -r '$s=@fsockopen("127.0.0.1",8207); exit($s?0:1);'; then
	echo "Refusing occupied loopback port 8207" >&2
	exit 2
fi

cleanup() {
	status=$?
	trap - EXIT INT TERM
	if [[ -n "$SERVER_PID" ]]; then
		kill -- "-$SERVER_PID" 2>/dev/null || kill "$SERVER_PID" 2>/dev/null || true
		wait "$SERVER_PID" 2>/dev/null || true
	fi
	if [[ "$SETUP_STARTED" == 1 ]] && wp --path="$CLONE" option get opf_qfl_order_again_state >/dev/null 2>&1; then
		OPF_QFL_ORDER_AGAIN_ALLOW=1 OPF_QFL_ORDER_AGAIN_PHASE=cleanup wp --path="$CLONE" eval-file bin/e2e-price-quantity-flat-order-again.php || status=1
	fi
	if [[ -L "$MU_LINK" ]]; then
		actual=$(readlink "$MU_LINK")
		if [[ "$actual" == "$MU_TARGET" ]]; then unlink "$MU_LINK" || status=1; else echo "Refusing to remove unexpected MU symlink target: $actual" >&2; status=1; fi
	fi
	if php -r '$s=@fsockopen("127.0.0.1",8207); exit($s?0:1);'; then echo "Port 8207 remains open after server shutdown" >&2; status=1; fi
	exit "$status"
}
trap cleanup EXIT INT TERM

OPF_QFL_E2E_ALLOW=1 wp --path="$CLONE" eval-file bin/e2e-price-quantity-flat.php
ln -s "$MU_TARGET" "$MU_LINK"
OPF_QFL_ORDER_AGAIN_ALLOW=1 wp --path="$CLONE" eval-file bin/e2e-price-quantity-flat-order-again.php
SETUP_STARTED=1
setsid wp --path="$CLONE" server --host=127.0.0.1 --port=8207 >/dev/null 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
	if curl --max-time 1 -fsS http://127.0.0.1:8207/ >/dev/null 2>&1; then break; fi
	sleep 0.25
done
curl --max-time 2 -fsS http://127.0.0.1:8207/ >/dev/null
OPF_QFL_ORDER_AGAIN_ALLOW=1 node bin/e2e-price-quantity-flat-order-again.mjs
OPF_QFL_ORDER_AGAIN_ALLOW=1 OPF_QFL_ORDER_AGAIN_PHASE=verify wp --path="$CLONE" eval-file bin/e2e-price-quantity-flat-order-again.php
echo "Order-again proof passed; cleanup runs automatically on exit."
