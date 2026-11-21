#!/usr/bin/env bash
set -euo pipefail

maho_source=d57ea65a139101d32fcd2e773e77c65eb9549645
docker_source=35aeff87ea03d36e87176a7d865144f37d2c25c8
base_tag=dunglas/frankenphp:php8.6-trixie
base_digest=sha256:a583367e9f332588e67d6a18c51d8c5f7edcc8cb27715a0138a1bc0ccc116807
base_image="docker.io/dunglas/frankenphp@$base_digest"
platform="linux/${PROOF_ARCH:?}"
owned="$(mktemp -d "${RUNNER_TEMP:?}/maho-frankenphp.XXXXXX")"
container="$(basename "$owned")"
image="maho-php86-proof:$container"

cleanup() {
    local result=$?
    trap - EXIT
    if docker container inspect "$container" >/dev/null 2>&1; then
        if [[ "$result" != 0 ]]; then
            docker logs "$container" || true
        fi
        docker rm -fv "$container" >/dev/null
    fi
    if docker image inspect "$image" >/dev/null 2>&1; then
        docker image rm "$image" >/dev/null
    fi
    echo 'Owned image and test container removed'
    exit "$result"
}
trap cleanup EXIT

docker_arch=$(docker info --format '{{.Architecture}}')
case "$PROOF_ARCH:$docker_arch" in
    amd64:x86_64|amd64:amd64|arm64:aarch64|arm64:arm64) ;;
    *) echo "Expected native $PROOF_ARCH, found $docker_arch" >&2; exit 1 ;;
esac
git init -q "$owned/docker-images"
git -C "$owned/docker-images" fetch --no-tags --depth=1 https://github.com/MahoCommerce/docker-images.git "$docker_source"
git -C "$owned/docker-images" checkout -q --detach FETCH_HEAD
test "$(git -C "$owned/docker-images" rev-parse HEAD)" = "$docker_source"
patch="$(cd "$(dirname "$0")" && pwd)/php86-docker-nightly.patch"
echo "31dbb23dc4ffde028534ec4a615291752e685e443e3a4cc040226c99cbdf0a45  $patch" | sha256sum -c
git -C "$owned/docker-images" apply --unidiff-zero "$patch"
jq -e 'any(.[]; .tag == "nightly" and .php == "8.6" and .maho == "dev-main"
    and .debian == "trixie" and .composer_json == "26.11" and .caddyfile == "26.11"
    and .nodejs == "26" and .mysql and .pgsql and .sqlite)' "$owned/docker-images/versions.json"
docker buildx imagetools inspect --raw "$base_image" | jq -e --arg arch "$PROOF_ARCH" \
    'any(.manifests[]; .platform.os == "linux" and .platform.architecture == $arch)'

docker buildx build --platform "$platform" --load --no-cache --progress plain \
    --build-context "$base_tag=docker-image://$base_image" \
    --build-arg PHP_VERSION=8.6 --build-arg DEBIAN_VARIANT=trixie \
    --build-arg MYSQL=true --build-arg PGSQL=true --build-arg SQLITE=true \
    --build-arg COMPOSER_TEMPLATE=26.11 --build-arg CADDYFILE=26.11 \
    --build-arg "MAHO_VERSION=dev-main#$maho_source" --build-arg NODEJS=true --build-arg NODE_MAJOR=26 \
    --tag "$image" "$owned/docker-images"

test "$(docker image inspect "$image" --format '{{.Os}}/{{.Architecture}}')" = "$platform"
docker image inspect "$image" --format '{{.Id}} {{.Os}}/{{.Architecture}} {{.Size}}'
docker run --network none --rm --entrypoint php "$image" -r '
    printf("PHP %s, SAPI %s, ZTS %d, PDO %s\n", PHP_VERSION, PHP_SAPI, PHP_ZTS, implode(",", PDO::getAvailableDrivers()));
    exit(PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 6 || PHP_SAPI !== "cli" || !PHP_ZTS
        || array_diff(["mysql", "pgsql", "sqlite"], PDO::getAvailableDrivers()) ? 1 : 0);
'
docker run --network none --rm --entrypoint composer "$image" --no-plugins --no-scripts check-platform-reqs --no-dev
docker run --network none --rm --entrypoint composer "$image" --no-plugins --no-scripts show mahocommerce/maho --format=json \
    | jq -e --arg sha "$maho_source" '.source.reference == $sha'
docker run --network none --rm --entrypoint php "$image" /app/maho --version
docker run --network none --rm --entrypoint node "$image" --version | awk '/^v26\./ { ok=1; print } END { exit !ok }'
docker run --network none --rm --entrypoint sh "$image" -eu -c '
    test ! -e /app/app/etc/local.xml
    test "$(stat -c "%i" /etc/frankenphp/Caddyfile)" = "$(stat -c "%i" /etc/caddy/Caddyfile)"
    grep -q "rewrite @api_other /rest.php" /etc/frankenphp/Caddyfile
'

docker run -d --network none --name "$container" -e SERVER_NAME=:8080 "$image"
test "$(docker container inspect "$container" --format '{{.HostConfig.NetworkMode}}')" = none
docker exec -i "$container" sh -c 'cat > /app/public/maho-php86-probe.php' <<'PHP'
<?php
if (file_exists('/app/app/etc/local.xml')) {
    http_response_code(500);
    exit;
}
header('Content-Type: application/json');
echo json_encode(['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'zts' => (bool) PHP_ZTS], JSON_THROW_ON_ERROR);
PHP
docker exec "$container" curl --fail --silent --show-error --max-time 4 --retry 4 --retry-connrefused \
    http://127.0.0.1:8080/maho-php86-probe.php | jq -ec 'select((.php | startswith("8.6.")) and .sapi == "frankenphp" and .zts)'
