#!/usr/bin/env bash
# Ordinary releases never initialize the world or replay financial conversions.
set -Eeuo pipefail
trap 'printf "::error::Production release stopped at line %s (exit %s).\n" "$LINENO" "$?" >&2' ERR
expected_sha=${1:?Checked SHA required}
base=${2:-}
[[ "$expected_sha" =~ ^[a-f0-9]{40}$ && ( -z "$base" || "$base" =~ ^[a-f0-9]{40}$ ) ]]
repo=/var/www/api.ablakin.ru
cd "$repo"
[[ "$(pwd -P)" = "$repo" && ! -L "$repo" ]]
git() { command git -c safe.directory="$repo" "$@"; }
[[ "$(git symbolic-ref --short HEAD)" = master ]]
control="$repo/yii2/common/runtime/financial-maintenance"
[[ -s "$control/completed" && -s "$control/world-activation-verified.json" && -s "$control/rating-precision-verified.json" ]]
[[ ! -L "$control" && ! -L "$control/paused" && ! -L "$control/world-release.pending" && ! -e "$control/rating-precision.pending" && ! -e "$control/world-activation.pending" ]]
if [[ -e "$control/paused" || -e "$control/world-release.pending" ]]; then
  [[ -f "$control/world-release.pending" && "$(cat "$control/world-release.pending")" = "$expected_sha" ]]
fi
exec 8>"$control/operator.lock"
flock -n 8
git diff --cached --quiet
# The PHP entrypoint normalizes runtime permissions. Restore only Git's mode
# for these tracked sentinels, and only when their canonical content is unchanged.
for sentinel in yii2/api/runtime/.gitignore yii2/backend/runtime/.gitignore yii2/frontend/runtime/.gitignore; do
  [[ -f "$sentinel" && ! -L "$sentinel" ]] || continue
  if ! git diff --quiet -- "$sentinel"; then
    [[ "$(git hash-object --path="$sentinel" "$sentinel")" = "$(git rev-parse "HEAD:$sentinel")" ]]
    mode=$(git ls-files -s -- "$sentinel" | cut -d' ' -f1)
    case "$mode" in 100644) chmod 644 "$sentinel" ;; 100755) chmod 755 "$sentinel" ;; *) exit 1 ;; esac
    printf '[production-release] Restored unchanged runtime sentinel mode: %s\n' "$sentinel"
  fi
done
changes=$(git status --porcelain --untracked-files=no)
[[ -z "$changes" || "$changes" = ' M docker-compose.yaml' ]]
git fetch origin master
[[ "$(git rev-parse FETCH_HEAD)" = "$expected_sha" ]]
actual=$(git rev-parse HEAD)
git merge-base --is-ancestor "$actual" "$expected_sha"
# An older successful baseline is safe (a superset); a newer baseline than the
# actual checkout could hide unapplied code and must not shorten verification.
if [[ -n "$base" ]]; then git merge-base --is-ancestor "$base" "$actual"; fi
if [[ -n "$changes" ]]; then
  # Never stash away the operator's production configuration.
  git diff --quiet "$actual" "$expected_sha" -- docker-compose.yaml
fi
compose_checksum=$(sha256sum docker-compose.yaml | cut -d' ' -f1)
runtime=false; proxy=false; world_update=false; publish_profile=0
[[ ! -e "$control/world-release.pending" ]] || world_update=true
[[ -n "$base" ]] || runtime=true
while IFS= read -r path; do
  case "$path" in
    yii2/console/world-migrations/*) world_update=true; runtime=true ;;
    yii2/common/modules/world/data/economy/closed-test-v1.json) world_update=true; publish_profile=1; runtime=true ;;
    deploy/php-runtime-upgrade.sh) runtime=true ;;
    ''|docs/*|agreements/*|AGENTS.md|README.md|.gitignore|.github/*|deploy/*|yii2/tests/*) ;;
    docker/nginx/*) proxy=true ;;
    *) runtime=true ;;
  esac
done < <(git diff --no-renames --name-only "${base:-$actual}" "$expected_sha")
if [[ "$world_update" = true ]]; then
  printf '%s\n' "$expected_sha" > "$control/world-release.pending"
  touch "$control/paused"
  chmod 0644 "$control/paused"
  worker=ablaki-world-worker-production
  [[ "$(docker inspect -f '{{index .Config.Labels "ablaki.world.worker"}}' "$worker")" = production ]]
  docker stop --time 180 "$worker"
  docker-compose exec -T php service cron stop
  docker-compose exec -T php sh < deploy/php-drain.sh
  [[ -f "$control/writers.lock" && ! -L "$control/writers.lock" ]]
  exec 9<"$control/writers.lock"
  flock -x -w 180 9
fi
git merge --ff-only "$expected_sha"
[[ "$(git rev-parse HEAD)" = "$expected_sha" ]]
if [[ -n "$changes" ]]; then [[ "$(sha256sum docker-compose.yaml | cut -d' ' -f1)" = "$compose_checksum" ]]; fi
if [[ "$world_update" = true ]]; then
  docker-compose exec -T -e WORLD_RELEASE=confirmed-production-checkout php php /web/yii2/yii release/world-update "$publish_profile"
fi
if [[ "$runtime" = true ]]; then
  bash deploy/php-runtime-upgrade.sh "$repo" production
  make up
fi
if [[ "$proxy" = true ]]; then
  docker-compose exec -T nginx nginx -t
  docker-compose exec -T nginx nginx -s reload
fi
if [[ "$world_update" = true ]]; then
  docker-compose exec -T php sh < deploy/php-drain.sh
  docker start "$worker"
  docker-compose exec -T php service cron start
  [[ "$(docker inspect -f '{{.State.Running}}' "$worker")" = true ]]
  curl --fail --silent --show-error --max-time 30 http://127.0.0.1:2180/v1/stat >/dev/null
  rm -- "$control/paused"
  flock -u 9
  rm -- "$control/world-release.pending"
  printf '[production-release] World migrations/profile verified; protected state preserved; operations resumed.\n'
fi
printf '[production-release] Checked release %s applied without world initialization.\n' "$expected_sha"
