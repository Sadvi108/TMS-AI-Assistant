#!/bin/bash
# Starts everything the TMS assistant needs on this Mac — Docker (which runs Dify),
# Ollama and the PHP server — checks that a test question gets answered, then opens
# http://localhost:8000.
#
#   ./start.sh             start and open the browser
#   ./start.sh --no-open   start without opening the browser
#
# Ctrl+C stops the assistant's web server. Docker, Dify and Ollama keep running.
# Override defaults with PORT=8001 or DIFY_DIR=/path/to/dify/docker.

set -uo pipefail
cd "$(dirname "$0")"

PORT=${PORT:-8000}
DIFY_DIR=${DIFY_DIR:-$HOME/dify/docker}
URL="http://localhost:$PORT"
OPEN_BROWSER=1
[[ ${1:-} == --no-open ]] && OPEN_BROWSER=0
SERVER_PID=

step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; exit 1; }

# wait_for <seconds> <command...>: retries the command every 2 seconds until it succeeds.
wait_for() {
    local limit=$1 waited=0
    shift
    until "$@" >/dev/null 2>&1; do
        if ((waited >= limit)); then
            printf '\n'
            return 1
        fi
        sleep 2
        waited=$((waited + 2))
        printf '.'
    done
    printf '\n'
}

cleanup() {
    [[ -n $SERVER_PID ]] && pkill -P "$SERVER_PID" 2>/dev/null # PHP_CLI_SERVER_WORKERS processes
    if [[ -n $SERVER_PID ]] && kill "$SERVER_PID" 2>/dev/null; then
        printf '\nStopped the assistant at %s.\n' "$URL"
    fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM

env_value() { sed -n "s/^$1=//p" .env | tail -1; }

[[ -f .env ]] || fail "No .env file. Copy .env.example to .env and add your Dify key."
DIFY_BASE=$(env_value DIFY_API_BASE)
DIFY_BASE=${DIFY_BASE%/}
DIFY_KEY=$(env_value DIFY_API_KEY)
[[ $DIFY_KEY == app-* ]] || fail "DIFY_API_KEY in .env isn't set. It starts with app- (Dify app > Access Point)."

step "1/4  Docker"
if docker info >/dev/null 2>&1; then
    ok "already running"
else
    open -a Docker 2>/dev/null || fail "Couldn't open Docker Desktop. Is it installed?"
    printf '  starting'
    if ! wait_for 120 docker info; then
        # Docker Desktop can get stuck after an unclean shutdown ("unable to start"); a restart clears it.
        printf '  still not up, restarting Docker Desktop'
        docker desktop restart --timeout 120 >/dev/null 2>&1
        wait_for 60 docker info || fail "Docker didn't start. Open Docker Desktop and check its error message."
    fi
    ok "started"
fi

step "2/4  Dify"
dify_status() { curl -s -o /dev/null -w '%{http_code}' -m 5 -H "Authorization: Bearer $DIFY_KEY" "$DIFY_BASE/parameters"; }
dify_responding() { [[ $(dify_status) =~ ^[24] ]]; } # nginx answers 502 while Dify's API is still booting
if ! dify_responding; then
    # Dify's containers restart with Docker on their own; this also covers a stack that was stopped.
    if [[ $DIFY_BASE == http://localhost* && -d $DIFY_DIR ]]; then
        (cd "$DIFY_DIR" && docker compose up -d >/dev/null 2>&1) || fail "docker compose up failed in $DIFY_DIR"
    fi
    printf '  waiting for Dify'
    wait_for 240 dify_responding || fail "Dify didn't answer at $DIFY_BASE within 4 minutes. Check: cd $DIFY_DIR && docker compose ps"
fi
case $(dify_status) in
    200) ok "ready at ${DIFY_BASE%/v1}" ;;
    401 | 403) fail "Dify is up but rejected DIFY_API_KEY. Create a key under Access Point and update .env." ;;
    *) fail "Dify answered HTTP $(dify_status). Is the TMS Assistant workflow published?" ;;
esac

step "3/4  Ollama"
ollama_up() { curl -s -m 3 http://localhost:11434/api/tags >/dev/null; }
if ollama_up; then
    ok "already running"
else
    if [[ -d /Applications/Ollama.app ]]; then
        open -a Ollama
    elif command -v ollama >/dev/null; then
        nohup ollama serve >/dev/null 2>&1 &
    else
        fail "Ollama isn't installed. Dify needs it for the bge-m3 model that matches questions."
    fi
    printf '  starting'
    wait_for 60 ollama_up || fail "Ollama didn't start within a minute."
    ok "started"
fi
curl -s -m 5 http://localhost:11434/api/tags | grep -q '"bge-m3' || fail "The bge-m3 model is missing. Run: ollama pull bge-m3"
ok "bge-m3 model available"

step "4/4  TMS assistant"
command -v php >/dev/null || fail "PHP isn't installed. Run: brew install php"
if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    ok "already serving $URL"
else
    mkdir -p storage
    PHP_CLI_SERVER_WORKERS=4 php -S "localhost:$PORT" -t public >storage/php-server.log 2>&1 &
    SERVER_PID=$!
    printf '  starting'
    wait_for 15 curl -s -o /dev/null -m 2 "$URL/" || fail "The PHP server didn't start. See storage/php-server.log"
    ok "serving $URL"
fi

printf '  asking a test question'
reply=$(curl -sN -m 90 -X POST "$URL/api/chat.php" -H 'Content-Type: application/json' -d '{"query":"What is TMS?","lang":"en"}')
printf '\n'
if grep -q '"type":"end"' <<<"$reply"; then
    ok "test question answered"
else
    error=$(sed -n 's/.*"type":"error".*"message":"\([^"]*\)".*/\1/p' <<<"$reply" | head -1)
    fail "Test question failed: ${error:-no answer}. Check that Groq is reachable and the workflow is published."
fi

printf '\n\033[32;1mReady:\033[0m %s\n' "$URL"
((OPEN_BROWSER)) && open "$URL"

if [[ -n $SERVER_PID ]]; then
    echo "Press Ctrl+C to stop the assistant. Docker, Dify and Ollama keep running."
    wait "$SERVER_PID"
fi
