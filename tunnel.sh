#!/bin/bash
# Lets the Vercel site use the Dify running on this Mac: publishes Dify's app API
# (/v1 only, never the console) through a Cloudflare quick tunnel and prints the
# DIFY_API_BASE to set in Vercel.
#
#   ./tunnel.sh
#
# Run ./start.sh first so Dify and Ollama are up. The tunnel lives as long as this
# script; Ctrl+C closes it. A quick tunnel gets a new URL every start, so update
# DIFY_API_BASE in Vercel (Settings > Environment Variables) and redeploy each time.

set -uo pipefail
cd "$(dirname "$0")"

GATEWAY_PORT=${GATEWAY_PORT:-8787}
GATEWAY="http://127.0.0.1:$GATEWAY_PORT"
GATEWAY_PID=
TUNNEL_PID=

ok() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; exit 1; }

cleanup() {
    [[ -n $TUNNEL_PID ]] && kill "$TUNNEL_PID" 2>/dev/null
    if [[ -n $GATEWAY_PID ]]; then
        pkill -P "$GATEWAY_PID" 2>/dev/null # PHP_CLI_SERVER_WORKERS processes
        kill "$GATEWAY_PID" 2>/dev/null
    fi
    [[ -n $TUNNEL_PID ]] && printf '\nTunnel closed. The Vercel site can no longer reach Dify.\n'
}
trap cleanup EXIT
trap 'exit 130' INT TERM HUP

command -v cloudflared >/dev/null || fail "cloudflared isn't installed. Run: brew install cloudflared"
[[ $(curl -s -o /dev/null -w '%{http_code}' -m 5 http://localhost/v1/parameters) == 401 ]] ||
    fail "Dify isn't answering at http://localhost. Run ./start.sh first."
ok "local Dify is up"

if lsof -nP -iTCP:"$GATEWAY_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    fail "Port $GATEWAY_PORT is busy. Run with GATEWAY_PORT=8788 ./tunnel.sh"
fi
mkdir -p storage
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$GATEWAY_PORT" tools/dify-gateway.php >storage/gateway.log 2>&1 &
GATEWAY_PID=$!
for _ in $(seq 1 20); do
    curl -s -o /dev/null -m 1 "$GATEWAY/v1/parameters" && break
    sleep 0.5
done
ok "API-only gateway on $GATEWAY"

cloudflared tunnel --no-autoupdate --url "$GATEWAY" >storage/tunnel.log 2>&1 &
TUNNEL_PID=$!
printf '  opening tunnel'
URL=
for _ in $(seq 1 45); do
    URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' storage/tunnel.log | head -1)
    [[ -n $URL ]] && break
    sleep 1
    printf '.'
done
printf '\n'
[[ -n $URL ]] || fail "No tunnel URL after 45 s. See storage/tunnel.log"

# The new hostname takes a while to reach every resolver, and macOS caches the early
# "not found", so check through Cloudflare's DNS-over-HTTPS instead of the system's.
public() { curl -s -o /dev/null -w '%{http_code}' -m 5 --doh-url https://1.1.1.1/dns-query "$URL$1"; }
printf '  waiting for %s' "$URL"
for _ in $(seq 1 60); do
    [[ $(public /v1/parameters) == 401 ]] && break
    kill -0 "$TUNNEL_PID" 2>/dev/null || { printf '\n'; fail "cloudflared exited. See storage/tunnel.log"; }
    sleep 3
    printf '.'
done
printf '\n'
if [[ $(public /v1/parameters) == 401 ]]; then
    ok "Dify API reachable through the tunnel"
    [[ $(public /apps) == 404 ]] && ok "Dify console is not exposed"
else
    # New quick-tunnel names sometimes take several minutes to resolve; the tunnel itself is up.
    printf '  \033[33m!\033[0m not reachable from here yet; keeping the tunnel open, it usually starts working within a few minutes\n'
fi

printf '\n\033[32;1mSet this in Vercel:\033[0m  DIFY_API_BASE=%s/v1\n' "$URL"
echo "Keep this running while the Vercel site should answer. Ctrl+C closes the tunnel."
wait "$TUNNEL_PID"
