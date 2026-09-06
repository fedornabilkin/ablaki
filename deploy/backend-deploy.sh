#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

die() { printf '::error title=Backend deployment failed::%s\n' "$*" >&2; exit 1; }
log() { printf '[backend-deploy] %s\n' "$*"; }
phase='Checking deployment paths and artifact'
trap 'code=$?; printf "::error title=Backend deployment failed::%s (line %s, exit %s)\n" "$phase" "$LINENO" "$code" >&2' ERR
repo=${1:-}
sha=${2:-}
archive=${3:-}
api_url=${4:-}
deploy_root=${5:-}
branch=${6:-master}
target=${7:-production}
[[ "$target" = production || "$target" = test ]] || die 'Invalid deployment environment'
[[ "$branch" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$branch" != *..* ]] || die 'Invalid branch'
[[ "$target" != production || "$branch" = master ]] || die 'Production only accepts master'
[[ "$repo" =~ ^/[A-Za-z0-9._/-]+$ && "$repo" != '/' && "$repo" != *..* ]] || die 'Invalid repository path'
[[ "$deploy_root" =~ ^/[A-Za-z0-9._/-]+$ && "$deploy_root" != '/' && "$deploy_root" != *..* ]] || die 'Invalid deployment root'
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || die 'Invalid release SHA'
[[ "$api_url" =~ ^https?://[A-Za-z0-9._:-]+(/[A-Za-z0-9._/-]+)?/$ ]] || die 'Invalid API base URL (must end with /)'
[[ "$api_url" != *..* ]] || die 'Invalid API base path'
api_host=${api_url#*://}; api_host=${api_host%%/*}; api_host=${api_host%%:*}
[[ "$target" != test || "${api_host,,}" != api.ablakin.ru ]] || die 'Test cannot use the production API host'
for tool in git docker make curl flock tar sha256sum readlink find mktemp cut tr chmod; do command -v "$tool" >/dev/null || die "$tool is required"; done
repo=$(readlink -f "$repo")
state=$(readlink -f "$deploy_root")
[[ "$repo" != '/' && "$state" != '/' ]] || die 'Resolved paths must not be the root filesystem'
[[ -d "$repo/.git" && -d "$repo/yii2" ]] || die 'Existing checkout with a .git directory is required'
[[ -d "$state" && -w "$state" && -x "$state" ]] || die 'Prepare a writable deployment root for the SSH user first'
case "$state/" in "$repo/"*) die 'Deployment root must be outside the checkout' ;; esac
case "$repo/" in "$state/"*) die 'Checkout must be outside the deployment root' ;; esac
cd "$repo"
# Trust only this validated checkout for this process, without global Git changes.
git() { command git -c safe.directory="$repo" "$@"; }
[[ "$(git rev-parse --show-toplevel)" = "$repo" ]] || die 'Repository path mismatch'
git check-ref-format --branch "$branch" > /dev/null
previous_branch=$(git branch --show-current)
[[ -n "$previous_branch" ]] || die 'The VPS checkout must use a branch'
[[ "$target" != production || "$previous_branch" = master ]] || die 'The production checkout must use master'
git diff --quiet && git diff --cached --quiet || die 'Tracked local changes must be preserved before deployment'
mkdir -p "$state/releases"
exec 9>"$state/deploy.lock"
flock -w 600 9 || die 'Another deployment holds the lock'
[[ "$(git branch --show-current)" = "$previous_branch" ]] || die 'Checkout changed while waiting for the lock'
git diff --quiet && git diff --cached --quiet || die 'Tracked files changed while waiting for the lock'
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
archive=$(readlink -f "$archive")
[[ "$archive" = "$state/incoming/$sha/vendor.tar.gz" && -s "$archive" && -s "$archive.sha256" ]] || die 'Invalid or missing incoming artifact'
(cd "$(dirname "$archive")" && sha256sum -c vendor.tar.gz.sha256)
while IFS= read -r entry; do
  [[ "$entry" != /* && "/$entry/" != */../* ]] || die 'Unsafe archive path'
  case "$entry" in vendor/|vendor/*|commit.txt|composer.lock.sha256) ;; *) die 'Unexpected archive entry' ;; esac
done < <(tar -tzf "$archive")
release=$(mktemp -d "$state/releases/$sha.XXXXXXXX")
tar -xzf "$archive" -C "$release" --no-same-owner --no-same-permissions
[[ "$(tr -d '\r\n' < "$release/commit.txt")" = "$sha" ]] || die 'Artifact SHA mismatch'
[[ -f "$release/vendor/autoload.php" && -f "$release/vendor/bin/deploy-composer.phar" ]] || die 'Incomplete vendor artifact'
while IFS= read -r -d '' link; do
  case "$(readlink -f "$link")" in "$release/vendor/"*) ;; *) die 'Vendor symlink escapes the artifact' ;; esac
done < <(find "$release/vendor" -type l -print0)
chmod -R u=rwX,go=rX "$release/vendor"

phase='Fetching the selected Git branch'
git fetch --no-tags origin "$branch"
[[ "$(git rev-parse "origin/$branch")" = "$sha" ]] || { log 'A newer branch revision exists; skipping this outdated deployment'; exit 0; }
previous=$(git rev-parse HEAD)
if git show-ref --verify --quiet "refs/heads/$branch"; then
  git merge-base --is-ancestor "refs/heads/$branch" "$sha" || die 'The VPS target branch has commits absent from the release'
fi
expected_lock=$(tr -d '\r\n' < "$release/composer.lock.sha256")
actual_lock=$(git show "$sha:yii2/composer.lock" | sha256sum | cut -d' ' -f1)
[[ "$expected_lock" =~ ^[a-f0-9]{64}$ && "$actual_lock" = "$expected_lock" ]] || die 'Vendor was built from another lock file'
if docker compose version >/dev/null 2>&1; then compose=(docker compose); else compose=(docker-compose); fi
phase='Checking Docker Compose and the existing environment'
[[ -f .env ]] || die 'The checkout needs its existing .env file before deployment'
# Add deployment metadata only; retain all existing connection settings verbatim.
if ! grep -Eq '^[[:space:]]*(export[[:space:]]+)?APP_ENVIRONMENT[[:space:]]*=' .env; then
  printf '\nAPP_ENVIRONMENT=%s\n' "$target" >> .env
fi
"${compose[@]}" config --quiet
for service in php nginx; do
  container_id=$("${compose[@]}" ps -q "$service")
  if [[ -n "$container_id" ]]; then
    container_repo=$(docker inspect --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$container_id")
    [[ -n "$container_repo" && "$(readlink -f "$container_repo")" = "$repo" ]] || die 'Application container belongs to another checkout'
  fi
done
phase='Checking PHP 7.3+ and APP_ENVIRONMENT for the selected target'
"${compose[@]}" run --rm --no-deps -T --entrypoint php php -r 'exit(PHP_VERSION_ID >= 70300 && getenv("APP_ENVIRONMENT") === $argv[1] ? 0 : 1);' "$target"

stopped=0
changed=0
on_exit() {
  code=$?
  trap - EXIT
  [[ "$code" != 0 ]] || return 0
  if [[ "$stopped" = 1 && "$changed" = 0 ]]; then
    log 'Checkout and vendor are unchanged; restarting the previous application'
    "${compose[@]}" start php nginx || true
  elif [[ "$changed" = 1 ]]; then
    log "Release failed; API remains stopped. Previous SHA: $previous. Recovery data: $release"
    "${compose[@]}" stop nginx php || true
  fi
  exit "$code"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
log 'Updating the existing application; no automatic database backup'
log 'Stopping API and cron to replace code and dependencies'
stopped=1
phase='Stopping the previous PHP/nginx application'
"${compose[@]}" stop nginx php composer

printf '%s\n' "$previous" > "$release/previous-sha"
printf '%s\n' "$previous_branch" > "$release/previous-branch"
log 'Installing checked code and dependencies; keeping existing environment files'
changed=1
phase='Installing the selected code and vendor'
# Git-created code/directories must remain readable by FPM/nginx users in bind mounts.
(
  umask 022
  if [[ "$previous_branch" != "$branch" ]]; then
    if git show-ref --verify --quiet "refs/heads/$branch"; then git checkout "$branch";
    else git checkout -b "$branch" --track "origin/$branch"; fi
  fi
  git merge --ff-only "$sha"
)
[[ "$(git rev-parse HEAD)" = "$sha" ]]
if [[ -e yii2/vendor ]]; then mv yii2/vendor "$release/previous-vendor"; fi
mv "$release/vendor" yii2/vendor
if [[ -n "$(git diff --name-only "$previous" "$sha" -- docker/php/Dockerfile docker/php/cron docker/php/xdebugInstall.sh)" ]]; then
  phase='Building the changed PHP image'
  "${compose[@]}" build php
fi
phase='Checking installed PHP dependencies'
"${compose[@]}" run --rm --no-deps -T --workdir /web/yii2 --entrypoint php php vendor/bin/deploy-composer.phar check-platform-reqs --no-dev
# Keep the owner's existing release sequence: update code, then make up with migrations.
phase='Running make up with migrations against the existing database'
make up
phase='Checking the deployed API version and environment'
ready=0
for attempt in {1..12}; do
  if "${compose[@]}" exec -T --workdir /web/yii2 php php /dev/stdin 'http://nginx/' "$sha" "$target" < "$script_dir/check-api.php" \
    && curl --fail --silent --show-error --connect-timeout 5 --max-time 15 "${api_url}health" > "$release/public-health.json" \
    && "${compose[@]}" exec -T php php -r '$d=json_decode(stream_get_contents(STDIN),true);exit(is_array($d)&&($d["status"]??null)==="ok"&&($d["revision"]??null)===$argv[1]&&($d["portalListsVersion"]??null)===1&&($d["environment"]??null)===$argv[2]?0:1);' "$sha" "$target" < "$release/public-health.json"; then
    ready=1; break
  fi
  sleep 5
done
[[ "$ready" = 1 ]]
printf '%s\n' "$sha" > "$state/current.tmp"
mv "$state/current.tmp" "$state/current"
changed=0
stopped=0
log "Deployment verified: $sha"
