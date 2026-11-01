#!/usr/bin/env bash
# Preserve serving processes and operator configuration; widen only rating columns.
set -Eeuo pipefail
umask 022
expected_sha=${1:?Checked SHA required}
repo=/var/www/api.ablakin.ru
cd "$repo"
[[ "$(pwd -P)" = "$repo" && "$expected_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$(git -c safe.directory="$repo" rev-parse HEAD)" = "$expected_sha" ]]
control="$repo/yii2/common/runtime/financial-maintenance"
[[ -f "$control/completed" && ! -L "$control" && ! -L "$control/paused" && ! -L "$control/writers.lock" ]]
phase=preflight
trap 'code=$?; printf "::error::Rating upgrade stopped during %s. Keep the pause and pending markers; retry this checked release.\n" "$phase" >&2; exit "$code"' ERR
exec 8>"$control/operator.lock"
flock -n 8
if [[ -f "$control/rating-precision.pending" ]]; then
  [[ "$(cat "$control/rating-precision.pending")" = "$expected_sha" ]]
  if [[ -f "$control/rating-precision-verified.json" && ! -e "$control/paused" ]]; then
    rm -- "$control/rating-precision.pending"
    printf '[world-production] Rating precision release applied; stored values verified.\n'
    exit 0
  fi
else
  [[ ! -e "$control/paused" ]]
  printf '%s\n' "$expected_sha" > "$control/rating-precision.pending"
fi
php_id=''
for candidate in $(docker-compose ps -q php); do
  oneoff=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.oneoff"}}' "$candidate")
  [[ "$oneoff" != True && "$oneoff" != true ]] || continue
  [[ -z "$php_id" ]]
  php_id=$candidate
done
[[ "$php_id" =~ ^[a-f0-9]+$ && "$(docker inspect -f '{{.State.Running}}' "$php_id")" = true ]]
phase='pausing and draining rating writers'
touch "$control/paused"
chmod 0644 "$control/paused"
worker=ablaki-world-worker-production
if docker container inspect "$worker" >/dev/null 2>&1; then
  [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = production ]]
  if [[ "$(docker inspect -f '{{.State.Running}}' "$worker")" = true ]]; then
    touch "$control/rating-worker-running"
    docker stop --time 180 "$worker"
  fi
fi
docker exec "$php_id" service cron stop
docker exec -i "$php_id" sh < "$repo/deploy/php-drain.sh"
exec 9<"$control/writers.lock"
flock -x -w 180 9
phase='widening rating precision and comparing every stored value'
docker exec -i -e WORLD_RATING_PRECISION=confirmed-production-checkout "$php_id" php -d memory_limit=768M < "$repo/deploy/world-rating-precision.php"
[[ -s "$control/rating-precision-verified.json" ]]
phase='restoring scheduled operations'
docker exec -i "$php_id" sh < "$repo/deploy/php-drain.sh"
curl --fail --silent --show-error --max-time 20 http://127.0.0.1:2180/v1/stat >/dev/null
docker exec "$php_id" service cron start
if [[ -f "$control/rating-worker-running" ]]; then
  docker start "$worker"
  [[ "$(docker inspect -f '{{.State.Running}}' "$worker")" = true ]]
fi
rm -- "$control/paused"
flock -u 9
rm -- "$control/rating-precision.pending"
[[ ! -f "$control/rating-worker-running" ]] || rm -- "$control/rating-worker-running"
printf '[world-production] Rating precision release applied; stored values verified.\n'
