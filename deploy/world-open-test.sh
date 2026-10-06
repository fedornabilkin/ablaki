#!/usr/bin/env bash
# Fully prepare the established test environment, including existing property and credits.
set -Eeuo pipefail
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
[[ "$(git symbolic-ref --quiet --short HEAD)" = test ]]
[[ -f .env && ! -L .env && -w .env ]]
deploy_root=/opt/ablaki-backend-test
exec 9>"$deploy_root/world-setup.lock"
flock -n 9
umask 077
pending="$deploy_root/world-activation.pending"
worker=ablaki-world-worker-test

if docker container inspect "$worker" >/dev/null 2>&1; then
  [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = test ]]
  docker stop --time 60 "$worker"
  docker rm "$worker"
fi
touch "$pending"
trap 'printf "Test activation interrupted. Resume by deploying test again; checkpoints and backup are retained.\n" >&2' ERR

# Stop FPM, legacy cron and in-flight writers before converting property or money.
docker-compose stop -t 60 nginx php
mkdir -p "$deploy_root/backups"
backup="$deploy_root/backups/world-$(date -u +%Y%m%dT%H%M%S)-$$.dump"
# The established test uses the compose PostgreSQL service, unlike production MySQL/MariaDB.
docker-compose exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup.partial"
test -s "$backup.partial"
mv -- "$backup.partial" "$backup"
printf 'Test database backup saved: %s\n' "$backup"

# Verify both backup restoration and the new schema against an isolated copy first.
validation_db="ablaki_world_verify_$(date -u +%Y%m%d%H%M%S)_$$"
cleanup_validation() {
  [[ "$validation_db" =~ ^ablaki_world_verify_[0-9]+_[0-9]+$ ]] || return 1
  docker-compose exec -T postgres sh -c 'exec dropdb --if-exists -U "$POSTGRES_USER" "$1"' sh "$validation_db"
}
trap cleanup_validation EXIT
docker-compose exec -T postgres sh -c 'exec createdb -U "$POSTGRES_USER" -T template0 "$1"' sh "$validation_db"
docker-compose exec -T postgres sh -c 'exec pg_restore --exit-on-error -U "$POSTGRES_USER" -d "$1"' sh "$validation_db" < "$backup"
docker-compose run --rm --no-deps -T --entrypoint php \
  -e WORLD_INSTALL=confirmed-world-install -e WORLD_TEST_SETUP=confirmed-test-checkout \
  -e WORLD_TEST_MODE=1 -e WORLD_VERIFY_DATABASE="$validation_db" \
  php /web/deploy/world-verify-test.php
cleanup_validation
trap - EXIT

# No operator switches after deployment. Preserve unrelated application settings.
for name in WORLD_READ WORLD_WRITE STORAGE_V2 ECONOMY_TICK WORLD_WORKER WORLD_TEST_MODE; do
  sed -i "/^${name}=/d" .env
  printf '\n%s=1\n' "$name" >> .env
done

docker-compose run --rm --no-deps -T --entrypoint php \
  -e WORLD_INSTALL=confirmed-world-install -e WORLD_TEST_SETUP=confirmed-test-checkout \
  php /web/yii2/yii world-setup/test-ready

# Exercise registered handlers once before opening HTTP.
docker-compose run --rm --no-deps -T --entrypoint php php /web/yii2/yii world-worker/run 200
docker-compose up -d --no-deps php nginx

# Reuse the same image, env_file, network and mounts; no image rebuild or host cron.
docker-compose run -d --no-deps --name "$worker" --label ablaki.world.worker=test \
  --entrypoint /bin/sh php -c 'while true; do php /web/yii2/yii world-worker/run 200; sleep 5; done'
docker update --restart unless-stopped "$worker" >/dev/null
[[ "$(docker inspect -f '{{.State.Running}}' "$worker")" = true ]]
rm -- "$pending"
trap - ERR
printf 'Test world is ready: canonical storage, personal credits, finance, content and worker are active.\n'
