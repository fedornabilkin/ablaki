#!/usr/bin/env bash
# Test releases reuse a verified installation; changed installers retain the full path.
set -Eeuo pipefail
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
[[ "$(git symbolic-ref --quiet --short HEAD)" = test ]]
[[ -f .env && ! -L .env && -w .env ]]
profile_mode=${1:-changed}
profile_author=${2:-fedornabilkin}
[[ "$profile_mode" = changed || "$profile_mode" = preserve || "$profile_mode" = publish ]]
[[ "$profile_author" =~ ^[A-Za-z0-9_.@-]{1,150}$ ]]
deploy_root=/opt/ablaki-backend-test
exec 9>"$deploy_root/world-setup.lock"
flock -n 9
umask 077
pending="$deploy_root/world-activation.pending"
marker="$deploy_root/world-installation.sha256"
profile_marker="$deploy_root/world-profile.sha256"
worker=ablaki-world-worker-test
world=false
[[ ! -f yii2/common/modules/world/Module.php ]] || world=true
fingerprint=$(git ls-files -z -- 'yii2/console/*migrations/*.php' 'yii2/console/controllers/*SetupController.php' 'yii2/common/modules/*/commands/*SetupController.php' 'yii2/common/modules/world/models/domain/*Setup.php' 'yii2/common/modules/world/models/domain/*Catalog.php' 'yii2/common/modules/world/models/domain/WorldSeeder.php' 'yii2/common/modules/world/models/domain/TestWorldDefaults.php' 'yii2/common/modules/world/models/domain/DefaultTreasuryPolicy.php' 'yii2/common/modules/world/models/migration/*.php' 'yii2/common/modules/world/data/*.php' 'yii2/common/modules/craft/data/*' 'yii2/common/modules/world/modules/craft/data/*' 'yii2/common/modules/world/models/domain/WorldFlags.php' 'yii2/common/modules/world/models/RegistryConfiguration.php' 'yii2/common/modules/world/modules/craft/models/domain/Storage*.php' 'yii2/common/modules/world/modules/economy/models/domain/Wallet*.php' 'yii2/common/config/*.php' 'yii2/console/config/*.php' 'yii2/composer.lock' | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)
cli() {
  docker-compose run --rm --no-deps -T --entrypoint php -e WORLD_INSTALL=confirmed-world-install -e WORLD_TEST_SETUP=confirmed-test-checkout -e WORLD_TEST_MODE=1 php /web/yii2/yii "$@" </dev/null
}
stop_worker() {
  if docker container inspect "$worker" >/dev/null 2>&1; then
    [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = test ]]
    docker stop --time 60 "$worker"
    docker rm "$worker"
  fi
}
trap 'printf "Test release interrupted; installation/profile checkpoints are retained.\n" >&2' ERR
setup=true
if [[ ! -e "$pending" && -f "$marker" && "$(cat "$marker")" = "$fingerprint" ]] && cli release/test-state; then
  setup=false
  printf '[test-release] Installation already current; no seed, backfill or application stop\n'
fi
if [[ "$setup" = true ]]; then
  touch "$pending"
  stop_worker
  docker-compose stop -t 60 nginx php
  for name in WORLD_READ WORLD_WRITE STORAGE_V2 ECONOMY_TICK WORLD_WORKER WORLD_TEST_MODE; do
    sed -i "/^${name}=/d" .env
    printf '\n%s=1\n' "$name" >> .env
  done
  cli release/test-install
fi
if [[ "$world" = true ]]; then
  profile_hash=$(sha256sum yii2/common/modules/world/data/economy/closed-test-v1.json | cut -d' ' -f1)
  apply=0
  if [[ "$profile_mode" = publish || ( "$profile_mode" = changed && -f "$profile_marker" && "$(cat "$profile_marker")" != "$profile_hash" ) ]]; then apply=1; fi
  cli release/test-profile "$apply" "$profile_author"
  cli release/test-npc "$profile_author"
  printf '%s\n' "$profile_hash" > "$profile_marker.new"
  mv -- "$profile_marker.new" "$profile_marker"
fi
if [[ "$setup" = true ]]; then
  docker-compose up -d --no-deps php nginx
  [[ "$world" != true ]] || cli release/test-worker 200
fi
if [[ "$world" = true ]]; then
  loop_hash=$(sha256sum yii2/console/world-worker-loop.sh | cut -d' ' -f1)
  php_id=''
  for candidate in $(docker-compose ps -q php); do
    oneoff=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.oneoff"}}' "$candidate")
    [[ "$oneoff" != True && "$oneoff" != true ]] || continue
    [[ -z "$php_id" ]]
    php_id=$candidate
  done
  [[ -n "$php_id" ]]
  image=$(docker inspect -f '{{.Image}}' "$php_id")
  expected="$image|true|$loop_hash"
  actual=''
  if docker container inspect "$worker" >/dev/null 2>&1; then
    [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = test ]]
    actual=$(docker inspect -f '{{.Image}}|{{.State.Running}}|{{index .Config.Labels "ablaki.world.worker.loop"}}' "$worker")
  fi
  if [[ "$actual" != "$expected" ]]; then
    stop_worker
    docker-compose run -d --no-deps --name "$worker" --label ablaki.world.worker=test --label "ablaki.world.worker.loop=$loop_hash" --entrypoint /bin/sh php /web/yii2/console/world-worker-loop.sh
    docker update --restart unless-stopped "$worker" >/dev/null
  fi
  [[ "$(docker inspect -f '{{.State.Running}}' "$worker")" = true ]]
fi
cli release/test-state > "$deploy_root/world-release-state.json.new"
mv -- "$deploy_root/world-release-state.json.new" "$deploy_root/world-release-state.json"
port=$(docker-compose port nginx 80)
port=${port##*:}
[[ "$port" =~ ^[0-9]+$ ]]
api="http://127.0.0.1:$port"
curl --fail --silent --show-error --connect-timeout 2 --max-time 5 "$api/v1/stat" -o "$deploy_root/api-smoke.json"
grep -q '"users"' "$deploy_root/api-smoke.json"
status=$(curl --silent --show-error --connect-timeout 2 --max-time 5 --request POST --header 'Content-Type: application/json' --data '{}' --output "$deploy_root/login-smoke.json" --write-out '%{http_code}' "$api/login")
[[ "$status" = 401 ]]
printf '%s\n' "$fingerprint" > "$marker.new"
mv -- "$marker.new" "$marker"
rm -f -- "$pending"
trap - ERR
printf '[test-release] READY %s; API/login, schema, profile and worker checked\n' "$(git rev-parse HEAD)"
cat "$deploy_root/world-release-state.json"
