#!/usr/bin/env bash
set -euo pipefail

proof_dir="${RUNNER_TEMP:?}/maho-php86-container-proof"
architecture="${PROOF_ARCH:?}"
platform="linux/$architecture"
franken_ref=894fde1cd7bf1f7324a27776952da849cd7d2c3e
maho_images_ref=35aeff87ea03d36e87176a7d865144f37d2c25c8
cloud_ref=be56e8508942c32833a81c5c1a6321a8ac8330c3
php_base='docker-image://docker.io/library/php@sha256:d84acf3155d907afaa5afa9b9457c5f12016b6dc7ade84659f40cdc505cc087d'
go_base='docker-image://docker.io/library/golang@sha256:f18c3d73a184fb0582828510722e723b63a93f7188d11dd223d9be6f6880e65d'
base_tag=dunglas/frankenphp:php8.6-trixie
maho_tag=maho-php86-proof:maho
test_container=maho-php86-runtime-proof
label=org.mahocommerce.php86-proof

fetch_source() {
    local repo=$1 revision=$2 target=$3
    git init --quiet "$target"
    git -C "$target" fetch --no-tags --depth=1 "https://github.com/$repo.git" "$revision"
    git -C "$target" checkout --quiet --detach FETCH_HEAD
    test "$(git -C "$target" rev-parse HEAD)" = "$revision"
    git -C "$target" log -1 --format='%H %s'
    sha256sum "$target/Dockerfile"
}

inspect_image() {
    local ref=$1
    docker image inspect "$ref" --format '{{.Id}} {{.Os}}/{{.Architecture}} {{.Size}}'
    test "$(docker image inspect "$ref" --format '{{.Os}}/{{.Architecture}}')" = "$platform"
    docker run --network none --rm --entrypoint php "$ref" -r '
        printf("PHP %s, ZTS %d, CLI %s, PDO %s\n",
            PHP_VERSION, PHP_ZTS, PHP_SAPI, class_exists(PDO::class) ? implode(",", PDO::getAvailableDrivers()) : "");
        exit(PHP_VERSION !== "8.6.0" || !PHP_ZTS || PHP_SAPI !== "cli");
    '
    docker run --network none --rm --entrypoint frankenphp "$ref" version
}

serve_probe() {
    local ref=$1 response
    cleanup_probe() {
        local result=$?
        if [[ $result != 0 ]]; then
            docker logs "$test_container" || true
        fi
        docker rm -fv "$test_container" >/dev/null 2>&1 || true
    }
    trap cleanup_probe EXIT
    docker run -d --network none --name "$test_container" --label "$label" +        -e SERVER_NAME=:8080 "$ref"
    test "$(docker inspect "$test_container" --format '{{.HostConfig.NetworkMode}}')" = none
    docker exec -i "$test_container" sh -c 'cat > /app/public/maho-php86-runtime.php' <<'PHP'
<?php
if (file_exists('/app/app/etc/local.xml')) {
    http_response_code(500);
    exit;
}
header('Content-Type: application/json');
echo json_encode(['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'zts' => (bool) PHP_ZTS], JSON_THROW_ON_ERROR);
PHP
    response=$(docker exec "$test_container" curl --fail --silent --show-error --max-time 4 +        --retry 4 --retry-connrefused http://127.0.0.1:8080/maho-php86-runtime.php)
    jq -e '.php == "8.6.0" and .sapi == "frankenphp" and .zts' <<<"$response"
    printf 'FrankenPHP HTTP runtime %s\n' "$response"
    docker rm -fv "$test_container" >/dev/null
    trap - EXIT
}

case "${1:?verification stage}" in
    fetch)
        mkdir "$proof_dir"
        docker info --format 'Docker server {{.ServerVersion}} on {{.OSType}}/{{.Architecture}}'
        docker buildx ls
        fetch_source php/frankenphp "$franken_ref" "$proof_dir/frankenphp"
        fetch_source MahoCommerce/docker-images "$maho_images_ref" "$proof_dir/maho"
        fetch_source MahoCommerce/mahocloud-docker-images "$cloud_ref" "$proof_dir/cloud"
        ;;
    frankenphp)
        docker buildx build --builder default --platform "$platform" --load --progress plain +            --build-context "php-base=$php_base" --build-context "golang-base=$go_base" +            --target runner --build-arg "FRANKENPHP_VERSION=proof-$franken_ref" +            --tag "$base_tag" "$proof_dir/frankenphp"
        inspect_image "$base_tag"
        serve_probe "$base_tag"
        ;;
    maho)
        docker buildx build --builder default --platform "$platform" --load --progress plain +            --build-arg PHP_VERSION=8.6 --build-arg MAHO_VERSION=26.11.3 +            --build-arg MYSQL=true --build-arg PGSQL=true --build-arg SQLITE=true +            --build-arg NODEJS=true --build-arg NODE_MAJOR=26 +            --tag "$maho_tag" "$proof_dir/maho"
        inspect_image "$maho_tag"
        docker run --network none --rm --entrypoint composer "$maho_tag" check-platform-reqs --no-dev
        version=$(docker run --network none --rm --entrypoint php "$maho_tag" /app/maho --version)
        printf '%s\n' "$version"
        [[ "$version" == *26.11.3* ]]
        docker run --network none --rm --entrypoint sh "$maho_tag" -eu -c '
            test "$(stat -c "%i" /etc/caddy/Caddyfile)" = "$(stat -c "%i" /etc/frankenphp/Caddyfile)"
            grep -q "rewrite @api_other /rest.php" /etc/frankenphp/Caddyfile
            php -r "foreach ([\"pdo_mysql\", \"pdo_pgsql\", \"pdo_sqlite\"] as \$extension) {
                if (!extension_loaded(\$extension)) { exit(1); }
            }"
            node --version
        '
        serve_probe "$maho_tag"
        ;;
    cloud)
        original="$proof_dir/cloud/tests/image.sh"
        offline="$proof_dir/cloud-image-offline.sh"
        sed 's/docker run --rm/docker run --network none --rm/' "$original" > "$offline"
        diff -u "$original" "$offline" || test "$?" = 1
        for engine in sqlite mysql pgsql; do
            image="maho-php86-proof:cloud-$engine"
            docker buildx build --builder default --platform "$platform" --load --progress plain +                --build-arg PHP_VERSION=8.6 --build-arg "BASE_IMAGE=$base_tag" +                --target "$engine" --tag "$image" "$proof_dir/cloud"
            inspect_image "$image"
            bash "$offline" "$image" "$engine"
        done
        ;;
    cleanup)
        docker rm -fv "$test_container" >/dev/null 2>&1 || true
        test -z "$(docker ps -aq --filter "label=$label")"
        echo 'Verification containers removed.'
        ;;
    *)
        exit 2
        ;;
esac
