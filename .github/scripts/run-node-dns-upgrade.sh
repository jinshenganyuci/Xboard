#!/bin/sh
# Run on the build VM. No production mounts, credentials or Cloudflare requests.
set -eu
umask 077
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_dir/../.." && pwd)
: "${XBOARD_DNS_CANDIDATE_IMAGE:?Set the candidate image to validate}"
: "${XBOARD_DNS_TEST_ROOT:?Set an empty task-owned directory outside the checkout}"
XBOARD_DNS_OLD_IMAGE=${XBOARD_DNS_OLD_IMAGE:-ghcr.io/jinshenganyuci/xboard@sha256:bf4648121cd151d57d0657209cc4d4dbaac91f52c65192d3fe4f280904018251}
project=${XBOARD_DNS_TEST_PROJECT:-xboard-dns-upgrade-$(date +%s)-$$}
case "$project" in xboard-dns-upgrade-*) ;; *) echo 'Refusing a non-test Compose project name.' >&2; exit 2 ;; esac
case "$project" in *[!a-z0-9_-]*) echo 'Invalid test project name.' >&2; exit 2 ;; esac
case "$XBOARD_DNS_TEST_ROOT" in /*) ;; *) echo 'The isolated test root must be absolute.' >&2; exit 2 ;; esac
mkdir -p "$XBOARD_DNS_TEST_ROOT"
test_root=$(CDPATH= cd -- "$XBOARD_DNS_TEST_ROOT" && pwd -P)
case "$test_root/" in "$repository_root/"*|/) echo 'Refusing the source directory or filesystem root.' >&2; exit 2 ;; esac
if [ -n "$(find "$test_root" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
  echo 'Refusing a non-empty test directory.' >&2; exit 2
fi
if [ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" ] ||
   [ -n "$(docker network ls -q --filter "label=com.docker.compose.project=$project")" ]; then
  echo 'Refusing an existing Compose project.' >&2; exit 2
fi
mkdir -p "$test_root/data" "$test_root/redis" "$test_root/theme" "$test_root/state" "$test_root/scripts" \
  "$test_root/storage/app/public" "$test_root/storage/framework/cache/data" \
  "$test_root/storage/framework/sessions" "$test_root/storage/framework/views" "$test_root/storage/logs"
# The entrypoint assigns /data to redis; www must still traverse it to open
# /data/redis.sock. Keep the fixture/state directories private under umask 077.
chmod 0755 "$test_root/redis"
touch "$test_root/.env"
cp "$script_dir/dns-upgrade.compose.yaml" "$test_root/compose.yaml"
cp "$script_dir/seed-node-dns-upgrade.php" "$script_dir/smoke-node-dns.php" "$test_root/scripts/"
XBOARD_DNS_TEST_IMAGE=$XBOARD_DNS_OLD_IMAGE
export XBOARD_DNS_TEST_IMAGE
compose() { docker compose -p "$project" -f "$test_root/compose.yaml" "$@"; }
init_container=
cleanup() {
  result=$?
  trap - EXIT HUP INT TERM
  compose logs --no-color >"$test_root/state/containers.log" 2>&1 || true
  compose down -v --remove-orphans >>"$test_root/state/cleanup.log" 2>&1 || result=1
  if [ -n "$init_container" ]; then docker rm -v "$init_container" >>"$test_root/state/cleanup.log" 2>&1 || result=1; fi
  printf 'dns-upgrade exit=%s\n' "$result" >>"$test_root/state/result.txt"
  # Data and logs remain in the task-owned root for deliberate archival/cleanup.
  # The private fixture JSON and install log contain synthetic credentials only.
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM
docker image inspect "$XBOARD_DNS_OLD_IMAGE" >/dev/null 2>&1 || docker pull "$XBOARD_DNS_OLD_IMAGE" >"$test_root/state/pull-old.log" 2>&1
docker image inspect "$XBOARD_DNS_CANDIDATE_IMAGE" >/dev/null 2>&1 || docker pull "$XBOARD_DNS_CANDIDATE_IMAGE" >"$test_root/state/pull-candidate.log" 2>&1
# Populate the fresh theme bind from the old image, without starting services.
init_container=$(docker create --label "xboard.dns-upgrade.project=$project" --entrypoint /bin/true "$XBOARD_DNS_OLD_IMAGE")
docker cp "$init_container:/www/theme/." "$test_root/theme/"
docker rm -v "$init_container" >/dev/null
init_container=
wait_http() {
  attempts=0
  # Caddy can listen on 7001 before Octane is ready and return 502. Require a
  # completed Laravel API response instead of treating an open TCP socket as ready.
  # Never print the returned body (even when an exception page is returned).
  until compose exec -T xboard php -r '
    $c = curl_init("http://127.0.0.1:7001/api/v1/guest/comm/config");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1,
      CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => "", CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_HTTPHEADER => ["Accept: application/json"]]);
    $body = curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    $json = is_string($body) ? json_decode($body, true) : null;
    exit($status === 200 && is_array($json["data"] ?? null)
      && array_key_exists("is_captcha", $json["data"]) ? 0 : 1);
  ' >/dev/null 2>&1; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 90 ]; then echo 'HTTP startup timed out; inspect the task log.' >&2; return 1; fi
    sleep 2
  done
}
# Avoid a pipeline hiding PHP failures under POSIX sh.
run_stage() {
  stage=$1
  stage_script=smoke-node-dns.php
  if [ "$stage" = seed ]; then stage_script=seed-node-dns-upgrade.php; fi
  if compose exec -T -e DNS_UPGRADE_ALLOW_WRITE=1 -e "XBOARD_DNS_SMOKE_STAGE=$stage" \
    xboard php "/upgrade-scripts/$stage_script" >"$test_root/state/$stage.result" 2>&1; then
    cat "$test_root/state/$stage.result"
    cat "$test_root/state/$stage.result" >>"$test_root/state/result.txt"
  else
    cat "$test_root/state/$stage.result"
    return 1
  fi
}
compose up -d >"$test_root/state/old-start.log" 2>&1
wait_http
run_stage seed
run_stage baseline
compose stop -t 30 >>"$test_root/state/old-start.log" 2>&1
XBOARD_DNS_TEST_IMAGE=$XBOARD_DNS_CANDIDATE_IMAGE
export XBOARD_DNS_TEST_IMAGE
compose up -d --force-recreate >"$test_root/state/upgrade-start.log" 2>&1
wait_http
run_stage exercise
compose stop -t 30 >>"$test_root/state/upgrade-start.log" 2>&1
compose up -d --force-recreate >"$test_root/state/recreate-start.log" 2>&1
wait_http
run_stage persist
echo 'DNS upgrade, HTTP and second-start persistence checks passed.'
