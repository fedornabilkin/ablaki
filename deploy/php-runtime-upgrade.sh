#!/usr/bin/env bash
# A runtime change is explicit and resumable; ordinary code releases reuse the image.
set -Eeuo pipefail
repo=${1:?Repository path is required}
target=${2:?Deployment environment is required}
[[ "$target" = test || "$target" = production ]]
[[ "$repo" = /* && "$repo" != / && -d "$repo/.git" ]]
cd "$repo"
repo=$(pwd -P)
required=8.3.36
state="$repo/.php-runtime-upgrade"
mkdir -p "$state"
exec 8>"$state/upgrade.lock"
flock -n 8
fingerprint=$(sha256sum docker/php/Dockerfile docker/php/runtime.ini docker/php/security-fpm.conf docker/php/entrypoint.sh docker/composer/Dockerfile docker/composer/entrypoint.sh yii2/composer.json yii2/composer.lock | sha256sum | cut -d' ' -f1)
service_container() {
  local ids candidate oneoff selected=''
  ids=$(docker-compose ps -q "$1") || return
  for candidate in $ids; do
    oneoff=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.oneoff"}}' "$candidate") || return
    [[ "$oneoff" != True && "$oneoff" != true ]] || continue
    [[ -z "$selected" ]] || { printf 'Multiple primary containers for service %s\n' "$1" >&2; return 1; }
    selected=$candidate
  done
  printf '%s\n' "$selected"
}
php_id=$(service_container php)
old_image=''; old_ref=''; old_running=false; nginx_running=false; worker_running=false
if [[ -n "$php_id" ]]; then
  old_image=$(docker inspect -f '{{.Image}}' "$php_id")
  old_ref=$(docker inspect -f '{{.Config.Image}}' "$php_id")
  old_running=$(docker inspect -f '{{.State.Running}}' "$php_id")
  [[ -n "$old_ref" && "$old_ref" != sha256:* ]]
  version=$(docker run --rm --network none --entrypoint php "$old_image" -r 'echo PHP_VERSION;')
  if [[ "$version" = "$required" && -f "$state/active" && "$(cat "$state/active")" = "$fingerprint" && -f yii2/vendor/autoload.php ]]; then
    printf '[php-runtime] PHP %s and locked dependencies are already active\n' "$version"
    exit 0
  fi
fi
nginx_id=$(service_container nginx)
if [[ -n "$nginx_id" ]]; then nginx_running=$(docker inspect -f '{{.State.Running}}' "$nginx_id"); fi
worker='ablaki-world-worker-test'
if [[ "$target" = test ]] && docker container inspect "$worker" >/dev/null 2>&1; then
  [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = test ]]
  worker_running=$(docker inspect -f '{{.State.Running}}' "$worker")
fi
# Build successfully before stopping live requests or moving their dependencies.
docker-compose build --pull php composer
backup="$state/$(date -u '+%Y%m%dT%H%M%SZ')-$$"
[[ "$backup" = "$repo/.php-runtime-upgrade/"* && ! -e "$backup" ]]
mkdir -p "$backup"
printf '%s\n' "$old_image" > "$backup/image-id"
printf '%s\n' "$old_ref" > "$backup/image-reference"
[[ ! -L yii2/vendor ]]
writers_stopped=false; vendor_moved=false
rollback() {
  code=$?
  trap - ERR
  set +e
  printf '::error::PHP runtime upgrade failed; restoring the previous image and vendor\n' >&2
  if [[ "$writers_stopped" = true ]]; then
    docker-compose stop -t 60 php
    [[ ! -e yii2/vendor ]] || mv -- yii2/vendor "$backup/failed-vendor"
    [[ "$vendor_moved" != true ]] || mv -- "$backup/vendor" yii2/vendor
    if [[ -n "$old_image" && -n "$old_ref" && "$old_ref" != sha256:* ]]; then docker tag "$old_image" "$old_ref"; fi
    [[ "$old_running" != true ]] || docker-compose up -d --no-deps php
    [[ "$nginx_running" != true ]] || docker-compose up -d --no-deps nginx
    [[ "$worker_running" != true ]] || docker start "$worker"
  fi
  printf 'Runtime rollback assets retained at %s\n' "$backup" >&2
  exit "$code"
}
trap rollback ERR
mkdir -p "$backup/staged-vendor"
staged_mount="$backup/staged-vendor:/web/yii2/vendor"
options=(install --no-interaction --prefer-dist --no-progress --optimize-autoloader)
if [[ "$target" = production ]]; then options+=(--no-dev); fi
# Prepare the complete vendor in a private mount while the old application runs.
docker-compose run --rm --no-deps -T -v "$staged_mount" -e COMPOSER_ALLOW_SUPERUSER=1 --entrypoint composer php "${options[@]}"
docker-compose run --rm --no-deps -T -v "$staged_mount" --entrypoint composer php check-platform-reqs
docker-compose run --rm --no-deps -T -v "$staged_mount" --entrypoint php php -r 'require "/web/yii2/vendor/autoload.php"; if (PHP_VERSION !== "8.3.36" || ini_get("session.upload_progress.enabled") !== "0") exit(1); foreach (["pdo_mysql","pdo_pgsql","intl","gd","mbstring","zip"] as $extension) if (!extension_loaded($extension)) exit(1);'
writers_stopped=true
if [[ "$worker_running" = true ]]; then docker stop --time 60 "$worker"; fi
docker-compose stop -t 60 nginx php composer
if [[ -d yii2/vendor ]]; then mv -- yii2/vendor "$backup/vendor"; vendor_moved=true; fi
mv -- "$backup/staged-vendor" yii2/vendor
if [[ "$target" = test && "$repo" = /var/code/ablaki && -f /opt/ablaki-backend-test/world-activation.pending ]]; then
  printf '[php-runtime] Keeping application writers stopped until world activation resumes\n'
else
  docker-compose up -d --no-deps php
  docker-compose exec -T php php -r 'exit(PHP_VERSION === "8.3.36" ? 0 : 1);'
  ready=false
  for attempt in {1..60}; do
    if docker-compose exec -T php php -r '$socket = @fsockopen("127.0.0.1", 9000); if (!$socket) exit(1); fclose($socket);'; then ready=true; break; fi
    sleep 2
  done
  [[ "$ready" = true ]]
fi
printf '%s\n' "$fingerprint" > "$state/active.new"
mv -- "$state/active.new" "$state/active"
trap - ERR
printf '[php-runtime] PHP %s installed; previous image/vendor retained at %s\n' "$required" "$backup"
# The established deploy wrapper opens nginx; test activation recreates its worker.
