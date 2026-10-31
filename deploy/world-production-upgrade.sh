#!/usr/bin/env bash
# First world schema / exact-credit preparation. Later releases use the normal deploy.
set -Eeuo pipefail
umask 022
expected_sha=${1:?Checked release SHA is required}
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]]
repo=/var/www/api.ablakin.ru
deploy_root=/opt/ablaki-backend
cd "$repo"
[[ "$(pwd -P)" = "$repo" && ! -L "$repo" ]]
git() { command git -c safe.directory="$repo" "$@"; }
[[ "$(git symbolic-ref --short HEAD)" = master ]]
[[ -z "$(git status --porcelain --untracked-files=no)" ]]
control="$repo/yii2/common/runtime/financial-maintenance"
[[ ! -L "$control" ]]
if [[ -f "$control/completed" && ! -e "$control/paused" ]]; then
  exec bash "$deploy_root/backend-deploy.sh" "$repo" master production "$expected_sha"
fi
phase=preflight
trap 'code=$?; printf "::error::Production preparation stopped during %s (exit %s). Keep the pause marker; rerun this checked release to resume.\n" "$phase" "$code" >&2' ERR
git fetch origin master
[[ "$(git rev-parse FETCH_HEAD)" = "$expected_sha" ]]
git merge-base --is-ancestor HEAD "$expected_sha"
# This procedure must never invoke entrypoint migrations or replace a running container.
git diff --quiet --ignore-space-at-eol HEAD "$expected_sha" -- docker Makefile docker-compose.yml docker-compose.yaml yii2/composer.json yii2/composer.lock
[[ -f yii2/common/services/user/FinancialMaintenanceBootstrap.php ]]
grep -q FinancialMaintenanceBootstrap yii2/common/config/main.php
fingerprint=$(sha256sum docker/php/Dockerfile docker/php/runtime.ini docker/php/security-fpm.conf docker/php/entrypoint.sh docker/composer/Dockerfile docker/composer/entrypoint.sh yii2/composer.json yii2/composer.lock | sha256sum | cut -d' ' -f1)
[[ -f .php-runtime-upgrade/active && "$(cat .php-runtime-upgrade/active)" = "$fingerprint" ]]
php_id=$(docker-compose ps -q php)
[[ "$php_id" =~ ^[a-f0-9]+$ && "$(docker inspect -f '{{.State.Running}}' "$php_id")" = true ]]
docker exec "$php_id" sh -c 'test "$(cat /proc/1/comm)" = php-fpm'
docker exec "$php_id" php -r 'if (PHP_VERSION !== "8.3.36") exit(1);'
[[ ! -L "$control/writers.lock" && ! -L "$control/paused" ]]
mkdir -p "$control"
chmod 0755 "$control"
touch "$control/writers.lock"
chmod 0644 "$control/writers.lock"
# Serialize operators separately; the writer lock is acquired only after old processes drain.
exec 8>"$control/operator.lock"
flock -n 8
phase='pausing new monetary operations'
touch "$control/paused"
chmod 0644 "$control/paused"
sync
docker exec "$php_id" service cron stop
phase='draining old PHP requests and scheduled commands'
# A graceful FPM reload keeps the listener available, and retires pre-gate workers.
docker exec "$php_id" sh -ec '
  workers=$(ps -eo pid,args | awk "/php-fpm: pool/ && !/awk/ {print \$1}")
  kill -USR2 1
  deadline=$(( $(date +%s) + 180 ))
  while :; do
    alive=0
    for pid in $workers; do if kill -0 "$pid" 2>/dev/null; then alive=1; fi; done
    if ps -eo comm= | grep -qx php; then alive=1; fi
    [ "$alive" = 1 ] || break
    [ "$(date +%s)" -lt "$deadline" ] || exit 1
    sleep 1
  done
'
exec 9<"$control/writers.lock"
flock -x -w 180 9
curl --fail --silent --show-error --max-time 30 http://127.0.0.1:2180/v1/stat > /dev/null
phase='updating the checked application code'
git merge --ff-only "$expected_sha"
[[ "$(git rev-parse HEAD)" = "$expected_sha" ]]
phase='preparing engines, world schema and exact credits'
docker exec -i -e WORLD_PRODUCTION_UPGRADE=confirmed-production-checkout "$php_id" \
  php -d memory_limit=768M < "$repo/deploy/world-production-prepare.php"
[[ -s "$control/verified.json" ]]
phase='refreshing PHP and checking read access'
docker kill --signal=USR2 "$php_id" >/dev/null
ready=false
for attempt in {1..30}; do
  if curl --fail --silent --show-error --max-time 10 http://127.0.0.1:2180/v1/stat > /dev/null; then ready=true; break; fi
  sleep 1
done
[[ "$ready" = true ]]
docker exec "$php_id" service cron start
docker exec "$php_id" service cron status
phase='resuming monetary operations'
printf '%s\n' "$expected_sha" > "$control/completed.new"
mv -- "$control/completed.new" "$control/completed"
rm -- "$control/paused"
# Reads were available throughout; money writers are admitted only after this unlock.
flock -u 9
printf '[world-production] Verified exact wallet; financial operations resumed. World gameplay remains disabled.\n'
