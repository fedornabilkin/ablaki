#!/usr/bin/env bash
# Ordinary releases never initialize the world or replay financial conversions.
set -Eeuo pipefail
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
[[ ! -L "$control" && ! -e "$control/paused" && ! -e "$control/rating-precision.pending" && ! -e "$control/world-activation.pending" ]]
exec 8>"$control/operator.lock"
flock -n 8
changes=$(git status --porcelain --untracked-files=no)
[[ -z "$changes" || "$changes" = ' M docker-compose.yaml' ]]
git diff --cached --quiet
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
runtime=false; proxy=false
[[ -n "$base" ]] || runtime=true
while IFS= read -r path; do
  case "$path" in
    deploy/php-runtime-upgrade.sh) runtime=true ;;
    ''|docs/*|agreements/*|AGENTS.md|README.md|.gitignore|.github/*|deploy/*|yii2/tests/*) ;;
    docker/nginx/*) proxy=true ;;
    *) runtime=true ;;
  esac
done < <(git diff --no-renames --name-only "${base:-$actual}" "$expected_sha")
git merge --ff-only "$expected_sha"
[[ "$(git rev-parse HEAD)" = "$expected_sha" ]]
if [[ -n "$changes" ]]; then [[ "$(sha256sum docker-compose.yaml | cut -d' ' -f1)" = "$compose_checksum" ]]; fi
if [[ "$runtime" = true ]]; then
  bash deploy/php-runtime-upgrade.sh "$repo" production
  make up
fi
if [[ "$proxy" = true ]]; then
  docker-compose exec -T nginx nginx -t
  docker-compose exec -T nginx nginx -s reload
fi
printf '[production-release] Checked release %s applied without world initialization.\n' "$expected_sha"
