#!/usr/bin/env bash
set -euo pipefail

image="${1:?Cloud image}"
engine="${2:?database engine}"
stage="${RUNNER_TEMP:?}/maho-php86-container-proof"
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
fixture="$script_dir/cloud-php86-runtime.php.source"
packages="$stage/ffi-package"
socket_dir="$stage/redis-$engine"
redis_server=maho-php86-redis-proof
web_server=maho-php86-cloud-web-proof
label=org.mahocommerce.php86-proof
redis_image='docker.io/library/redis@sha256:7f7d30b7fdd09467bac58198a1f166dd69789d348d4fe859cccb20e0cf287fbe'

cleanup() {
    docker stop --time 5 "$redis_server" >/dev/null 2>&1 || true
    docker rm -fv "$web_server" "$redis_server" >/dev/null 2>&1 || true
    if [[ -S "$socket_dir/redis.sock" ]]; then
        if docker inspect "$redis_server" >/dev/null 2>&1; then
            return 1
        fi
        rm -- "$socket_dir/redis.sock"
    fi
    test ! -S "$socket_dir/redis.sock"
}
trap cleanup EXIT

if [[ ! -f "$packages/vendor/autoload.php" ]]; then
    mkdir "$packages"
    printf '%s\n' '{"name":"mahocommerce/php86-ffi-proof","require":{"jcupitt/vips":"2.6.1"}}' > "$packages/composer.json"
    docker run --rm --user "$(id -u):$(id -g)" --entrypoint composer \
        -v "$packages:/proof-vips" --workdir /proof-vips \
        -e COMPOSER_HOME=/tmp/maho-proof-composer "$image" \
        install --no-interaction --no-progress --no-dev --no-plugins --no-scripts --prefer-dist
fi

cli=(docker run --rm --network none --entrypoint php -e MAHO_OWNED_CLOUD_PROBE=1
    -v "$fixture:/proof-code/cloud.php:ro" -v "$packages:/proof-vips:ro" "$image")
cli_result=$("${cli[@]}" /proof-code/cloud.php image)
jq -e '.php=="8.6.0" and .sapi=="cli" and .geometry==[4,3] and .pixels==[[25,50,75],[180,140,100]]' <<<"$cli_result"
printf '%s CLI FFI %s\n' "$engine" "$cli_result"

web_request() {
    docker exec "$web_server" curl --silent --show-error --max-time 5 --retry 4 --retry-connrefused \
        --write-out '\n%{http_code}' http://127.0.0.1:8080/maho-cloud-ffi.php
}

start_web() {
    docker run -d --network none --name "$web_server" --label "$label" \
        -e MAHO_OWNED_CLOUD_PROBE=1 -e SERVER_NAME=:8080 \
        -v "$fixture:/app/public/maho-cloud-ffi.php:ro" -v "$packages:/proof-vips:ro" \
        "$@" "$image" >/dev/null
    test "$(docker inspect "$web_server" --format '{{.HostConfig.NetworkMode}}')" = none
}

start_web
default_response=$(web_request)
default_status="${default_response##*$'\n'}"
default_json="${default_response%$'\n'*}"
printf '%s web default HTTP %s %s\n' "$engine" "$default_status" "$default_json"
case "$default_status" in
    200)
        jq -e --arg sha "$(jq -r .png_sha256 <<<"$cli_result")" \
            '.php=="8.6.0" and .sapi=="frankenphp" and .png_sha256==$sha' <<<"$default_json"
        ;;
    424)
        jq -e '.php=="8.6.0" and .sapi=="frankenphp" and .blocked=="ffi.enable" and .ffi_enable=="preload"' <<<"$default_json"
        ;;
    *)
        docker logs "$web_server" >&2
        printf 'Unexpected web status %s\n' "$default_status" >&2
        exit 1
        ;;
esac
docker rm -fv "$web_server" >/dev/null

printf '%s\n' 'ffi.enable=true' > "$stage/ffi-proof.ini"
start_web -v "$stage/ffi-proof.ini:/usr/local/etc/php/conf.d/zz-maho-ffi-proof.ini:ro"
enabled_response=$(web_request)
enabled_status="${enabled_response##*$'\n'}"
enabled_json="${enabled_response%$'\n'*}"
printf '%s web explicitly enabled HTTP %s %s\n' "$engine" "$enabled_status" "$enabled_json"
test "$enabled_status" = 200
jq -e --arg sha "$(jq -r .png_sha256 <<<"$cli_result")" \
    '.php=="8.6.0" and .sapi=="frankenphp" and .png_sha256==$sha and .geometry==[4,3]' <<<"$enabled_json"
docker rm -fv "$web_server" >/dev/null

mkdir "$socket_dir"
docker run -d --network none --name "$redis_server" --label "$label" \
    --entrypoint redis-server -v "$socket_dir:/proof-socket" "$redis_image" \
    --port 0 --unixsocket /proof-socket/redis.sock --unixsocketperm 0777 --save "" --appendonly no >/dev/null
test "$(docker inspect "$redis_server" --format '{{.HostConfig.NetworkMode}}')" = none
for _ in {1..30}; do
    if [[ -S "$socket_dir/redis.sock" ]]; then
        break
    fi
    sleep 0.2
done
test -S "$socket_dir/redis.sock"
redis_cli=(docker run --rm --network none --entrypoint php -e MAHO_OWNED_CLOUD_PROBE=1
    -v "$fixture:/proof-code/cloud.php:ro" -v "$socket_dir:/proof-socket:ro")
redis_result=$("${redis_cli[@]}" "$image" /proof-code/cloud.php redis)
jq -e '.keys_remaining==0 and .ttl>0 and .ttl<=30' <<<"$redis_result"
printf '%s Redis %s\n' "$engine" "$redis_result"
session_options=(-d session.save_handler=redis
    -d 'session.save_path=unix:///proof-socket/redis.sock?database=1&prefix=maho-cloud-proof:'
    -d session.use_strict_mode=1)
session_id=$("${redis_cli[@]}" "$image" "${session_options[@]}" /proof-code/cloud.php session-write | jq -er .id)
session_result=$("${redis_cli[@]}" -e "MAHO_CLOUD_PROBE_SESSION=$session_id" \
    "$image" "${session_options[@]}" /proof-code/cloud.php session-read)
jq -e '.session_resumed and .session_keys_remaining==0' <<<"$session_result"
printf '%s Redis session %s\n' "$engine" "$session_result"
cleanup
trap - EXIT
printf '%s FFI and Redis proof completed with both containers removed.\n' "$engine"
