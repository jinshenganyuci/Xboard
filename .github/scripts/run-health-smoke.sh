#!/bin/sh
# Starts from a fresh, isolated Compose project and removes it on exit.
set -eu
cd "$(dirname "$0")"
project=${XBOARD_HEALTH_TEST_PROJECT:-xboard-release-health}
case "$project" in xboard-release-health*) ;; *) exit 2 ;; esac
compose() { docker compose -p "$project" -f health.compose.yaml "$@"; }
if [ -n "$(compose ps -aq)" ]; then echo 'Refusing to reuse an existing test project.' >&2; exit 1; fi
private=$(mktemp -d)
cleanup() { compose down -v --remove-orphans; rm -rf "$private"; }
trap cleanup EXIT
compose up -d
for n in $(seq 1 60); do
    if compose exec -T xboard php -r 'exit(@fsockopen("127.0.0.1",7001) ? 0 : 1);' >/dev/null 2>&1; then break; fi
    sleep 1
done
compose exec -T xboard php -r 'exit(@fsockopen("127.0.0.1",7001) ? 0 : 1);'
# A one-day CA confined to this throwaway container lets the proxy and both
# synthetic HTTPS targets exercise normal TLS verification without internet access.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout "$private/ca.key" -out "$private/ca.crt" -subj '/CN=Xboard disposable test CA' >/dev/null 2>&1
openssl req -new -newkey rsa:2048 -nodes -keyout "$private/server.key" -out "$private/server.csr" -subj '/CN=health.fixture.invalid' >/dev/null 2>&1
printf '%s\n' 'subjectAltName=DNS:health.fixture.invalid,DNS:www.gstatic.com,DNS:cp.cloudflare.com,IP:11.254.233.2' 'extendedKeyUsage=serverAuth' > "$private/san"
openssl x509 -req -in "$private/server.csr" -CA "$private/ca.crt" -CAkey "$private/ca.key" -CAcreateserial -days 1 -extfile "$private/san" -out "$private/server.crt" >/dev/null 2>&1
compose exec -T xboard mkdir -m 700 /tmp/health-fixture
for f in server.crt server.key; do compose cp "$private/$f" "xboard:/tmp/health-fixture/$f"; done
compose exec -T xboard sh -c 'cat >> /etc/ssl/certs/ca-certificates.crt' < "$private/ca.crt"
compose cp health-fixture.py xboard:/tmp/health-fixture/serve.py
compose exec -T -d xboard sh -c 'exec python3 /tmp/health-fixture/serve.py /tmp/health-fixture >/tmp/health-fixture/fixture.log 2>&1'
compose cp smoke-external-health.php xboard:/tmp/health-fixture/check.php
compose exec -T -e RELEASE_SMOKE_ALLOW_WRITE=1 xboard php /tmp/health-fixture/check.php
