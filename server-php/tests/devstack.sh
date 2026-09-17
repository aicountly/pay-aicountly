#!/usr/bin/env bash
# The whole product, running locally: database, API, portal/Manage stub, the
# built React app, and enough demo data to make every screen say something.
#
#   server-php/tests/devstack.sh            # bring it up and leave it running
#   server-php/tests/devstack.sh --smoke    # bring it up, run the browser pass, tear it down
#
# Nothing here reaches my.aicountly.com or any other product: tests/stub/router.php
# plays the portal, Manage and the source apps. Requires php with pdo_pgsql, a
# reachable PostgreSQL, and node for --smoke.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="$(cd "$ROOT/../web" && pwd)"

STUB_PORT="${STUB_PORT:-8794}"
API_PORT="${API_PORT:-8795}"
WEB_PORT="${WEB_PORT:-5199}"

SMOKE=0
[[ "${1:-}" == "--smoke" ]] && SMOKE=1

pids=()
cleanup() {
  for pid in "${pids[@]:-}"; do kill "$pid" 2>/dev/null || true; done
}
trap cleanup EXIT

# run.sh writes the .env this stack needs (including PORTAL_AUTH_BASE and the
# CORS origin) and applies the migrations, so the two never drift apart.
echo "→ migrations and schema"
STUB_PORT="$STUB_PORT" WEB_PORT="$WEB_PORT" bash "$ROOT/tests/run.sh" > /dev/null

echo "→ stub (portal, Manage, source apps) on :$STUB_PORT"
php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
pids+=($!)

echo "→ Pay API on :$API_PORT"
php -S "127.0.0.1:$API_PORT" "$ROOT/index.php" > /dev/null 2>&1 &
pids+=($!)

sleep 1
curl -sf "http://127.0.0.1:$API_PORT/health" > /dev/null || { echo "the API did not come up"; exit 1; }

echo "→ demo data"
php "$ROOT/tests/seed.php"

echo "→ building the app against http://127.0.0.1:$API_PORT"
( cd "$WEB" && VITE_API_BASE_URL="http://127.0.0.1:$API_PORT" VITE_APP_ENV=local npm run build > /dev/null )

echo "→ app on :$WEB_PORT"
php -S "127.0.0.1:$WEB_PORT" -t "$WEB/dist" "$WEB/spa.php" > /dev/null 2>&1 &
pids+=($!)
sleep 1

if [[ "$SMOKE" == "1" ]]; then
  # A real payment link, so the smoke pass can open the checkout a stranger sees.
  request=$(curl -s -H 'Authorization: Bearer stub.demo-owner' \
    "http://127.0.0.1:$API_PORT/v1/payment-requests?cmp_id=55&limit=1" \
    | php -r 'echo json_decode(stream_get_contents(STDIN), true)["data"][0]["payment_request_id"] ?? "";')
  token=$(curl -s -X POST -H 'Authorization: Bearer stub.demo-owner' -H 'Content-Type: application/json' \
    -H "Idempotency-Key: devstack-$RANDOM" \
    "http://127.0.0.1:$API_PORT/v1/payment-requests/$request/link?cmp_id=55" -d '{}' \
    | php -r '$u = json_decode(stream_get_contents(STDIN), true)["data"]["url"] ?? ""; echo $u === "" ? "" : substr($u, strrpos($u, "/") + 1);')

  echo "→ browser pass"
  APP_BASE="http://127.0.0.1:$WEB_PORT" CHECKOUT_TOKEN="$token" node "$WEB/tests/smoke.mjs"
  exit $?
fi

cat <<INFO

  Ready.

    app      http://127.0.0.1:$WEB_PORT
    api      http://127.0.0.1:$API_PORT/health
    sign-in  any auth_token works — the stub mints a session for it

  Ctrl-C to stop.
INFO

wait
