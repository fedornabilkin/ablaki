#!/usr/bin/env bash
# Explicit first public launch; never invokes test setup or entrypoint migrations.
set -Eeuo pipefail
umask 022
expected_sha=${1:?Checked SHA required}
reserve=${2:?Explicit starter reserve required}
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ && "$reserve" =~ ^[0-9]{1,6}(\.[0-9]{1,4})?$ ]]
repo=/var/www/api.ablakin.ru
cd "$repo"
[[ "$(pwd -P)" = "$repo" && ! -L "$repo" ]]
[[ "$(git -c safe.directory="$repo" rev-parse HEAD)" = "$expected_sha" ]]
control="$repo/yii2/common/runtime/financial-maintenance"
[[ -f "$control/completed" && ! -L "$control" && ! -L "$control/paused" && ! -L "$control/writers.lock" ]]
worker=ablaki-world-worker-production
phase=preflight
trap 'code=$?; printf "::error::World activation stopped at %s (exit %s). Keep the pause marker and resume this workflow.\n" "$phase" "$code" >&2; exit "$code"' ERR
exec 8>"$control/operator.lock"
flock -n 8
touch "$control/world-activation.pending"
php_id=$(docker-compose ps -q php)
[[ "$php_id" =~ ^[a-f0-9]+$ && "$(docker inspect -f '{{.State.Running}}' "$php_id")" = true ]]
if docker container inspect "$worker" >/dev/null 2>&1; then
  [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = production ]]
  docker stop --time 180 "$worker"
  docker rm "$worker"
fi
phase='pausing new financial operations and draining existing writers'
touch "$control/paused"
chmod 0644 "$control/paused"
docker exec "$php_id" service cron stop
docker exec -i "$php_id" sh < /opt/ablaki-backend/php-drain.sh
exec 9<"$control/writers.lock"
flock -x -w 180 9
curl --fail --silent --show-error --max-time 20 http://127.0.0.1:2180/v1/stat >/dev/null
phase='transferring inventories and preparing public world'
docker exec -i -e WORLD_PRODUCTION_UPGRADE=confirmed-production-checkout -e WORLD_PRODUCTION_ACTIVATE=confirmed-production-checkout \
  -e "WORLD_STARTER_RESERVE=$reserve" "$php_id" php -d memory_limit=768M < "$repo/deploy/world-production-activate.php"
[[ -s "$control/world-activation-verified.json" ]]
phase='enabling the installation switches'
settings="$repo/yii2/common/config/world-local.php"
[[ ! -L "$settings" ]]
if [[ -f "$settings" ]]; then cp -p "$settings" "$control/world-local-$(date -u '+%Y%m%dT%H%M%SZ').php"; fi
printf '%s\n' '<?php return ["world_read" => true, "world_write" => true, "storage_v2" => true, "economy_tick" => true];' > "$settings.new"
chmod 0644 "$settings.new"
mv -- "$settings.new" "$settings"
docker exec -i "$php_id" sh < /opt/ablaki-backend/php-drain.sh
phase='verifying effective world switches'
docker exec -i "$php_id" php < "$repo/deploy/world-production-inspect.php" > "$control/world-activation-state.json"
python3 - "$control/world-activation-state.json" <<'PY'
import json,sys
data=json.load(open(sys.argv[1]))['world_activation']
assert all(data['capabilities'][flag] for flag in ['world_read','world_write','storage_v2','economy_tick'])
assert data['public_access'] is True and data['npc']
print(json.dumps({'capabilities':data['capabilities'],'public_access':data['public_access']},ensure_ascii=False))
PY
phase='starting the persistent world worker'
docker-compose run -d --no-deps --name "$worker" --label ablaki.world.worker=production -e WORLD_WORKER=1 --entrypoint /bin/sh php /web/yii2/console/world-worker-loop.sh
docker update --restart unless-stopped "$worker" >/dev/null
[[ "$(docker inspect -f '{{.State.Running}}' "$worker")" = true ]]
docker exec "$php_id" service cron start
docker exec "$php_id" service cron status
printf '%s\n' "$expected_sha" > "$control/world-activated"
rm -- "$control/paused"
flock -u 9
rm -- "$control/world-activation.pending"
status=$(curl --silent --show-error --max-time 20 --output "$control/world-public-response.json" --write-out '%{http_code}' http://127.0.0.1:2180/v1/world)
[[ "$status" = 401 ]]
printf '[world-production] Public world enabled; inventories verified; financial operations resumed.\n'
