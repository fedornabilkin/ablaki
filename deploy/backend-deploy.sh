#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

die() { printf '[backend-deploy] ERROR: %s\n' "$*" >&2; exit 1; }
log() { printf '[backend-deploy] %s\n' "$*"; }
repo=${1:-}
sha=${2:-}
archive=${3:-}
api_url=${4:-}
[[ "$repo" =~ ^/[A-Za-z0-9._/-]+$ && "$repo" != '/' && "$repo" != *..* ]] || die 'Invalid repository path'
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || die 'Invalid release SHA'
[[ "$api_url" =~ ^https?://[A-Za-z0-9._:-]+(/[A-Za-z0-9._/-]+)?/$ ]] || die 'Invalid API base URL (must end with /)'
[[ "$api_url" != *..* ]] || die 'Invalid API base path'
for tool in git docker make curl flock tar sha256sum readlink find mktemp cut tr chmod; do command -v "$tool" >/dev/null || die "$tool is required"; done
repo=$(readlink -f "$repo")
[[ -d "$repo/.git" && -d "$repo/yii2" ]] || die 'Existing checkout with a .git directory is required'
cd "$repo"
[[ "$(git rev-parse --show-toplevel)" = "$repo" ]] || die 'Repository path mismatch'
[[ "$(git branch --show-current)" = master ]] || die 'The VPS checkout must use master'
git diff --quiet && git diff --cached --quiet || die 'Tracked local changes must be preserved before deployment'
state="$repo/.git/ablaki-deploy"
mkdir -p "$state/releases" "$state/backups"
exec 9>"$state/deploy.lock"
flock -w 600 9 || die 'Another deployment holds the lock'
[[ "$(git branch --show-current)" = master ]] || die 'Checkout changed while waiting for the lock'
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

git fetch --no-tags origin master
[[ "$(git rev-parse origin/master)" = "$sha" ]] || { log 'A newer master exists; skipping this outdated deployment'; exit 0; }
previous=$(git rev-parse HEAD)
git merge-base --is-ancestor "$previous" "$sha" || die 'VPS master has commits absent from the release'
expected_lock=$(tr -d '\r\n' < "$release/composer.lock.sha256")
actual_lock=$(git show "$sha:yii2/composer.lock" | sha256sum | cut -d' ' -f1)
[[ "$expected_lock" =~ ^[a-f0-9]{64}$ && "$actual_lock" = "$expected_lock" ]] || die 'Vendor was built from another lock file'
if docker compose version >/dev/null 2>&1; then compose=(docker compose); else compose=(docker-compose); fi
"${compose[@]}" config --quiet
[[ -n "$("${compose[@]}" ps -q postgres)" ]] || die 'Existing PostgreSQL container must be running'
"${compose[@]}" run --rm --no-deps -T --entrypoint php php -r 'exit(PHP_VERSION_ID >= 70300 ? 0 : 1);'

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
log 'Stopping API and cron for a consistent backup and update'
stopped=1
"${compose[@]}" stop nginx php composer

# Boot existing local console configuration with the incoming production dependencies first.
# A leftover development module must fail before changing the checkout or original vendor.
app_hash=$("${compose[@]}" run --rm --no-deps -T --volume "$release/vendor:/web/yii2/vendor:ro" --workdir /web/yii2 --entrypoint php php < "$script_dir/database-fingerprint.php")
db_identity=$("${compose[@]}" exec -T postgres sh -c 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "SELECT system_identifier::text || chr(47) || current_database() FROM pg_control_system()"')
db_hash=$(printf '%s' "$db_identity" | sha256sum | cut -d' ' -f1)
if [[ ! "$app_hash" =~ ^[a-f0-9]{64}$ || "$app_hash" != "$db_hash" ]]; then
  log 'Application DB differs from the Compose PostgreSQL database; checkout is unchanged'
  false
fi
backup="$state/backups/$(date -u +%Y%m%dT%H%M%SZ)-$previous.dump"
"${compose[@]}" exec -T postgres sh -c 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > "$backup.tmp"
test -s "$backup.tmp"
"${compose[@]}" exec -T postgres pg_restore --list < "$backup.tmp" > /dev/null
mv "$backup.tmp" "$backup"
sha256sum "$backup" > "$backup.sha256"
printf '%s\n' "$previous" > "$release/previous-sha"
log 'Database backup verified; installing checked code and dependencies'
changed=1
# Git-created code/directories must remain readable by FPM/nginx users in bind mounts.
(umask 022; git merge --ff-only "$sha")
[[ "$(git rev-parse HEAD)" = "$sha" ]]
if [[ -e yii2/vendor ]]; then mv yii2/vendor "$release/previous-vendor"; fi
mv "$release/vendor" yii2/vendor
if [[ -n "$(git diff --name-only "$previous" "$sha" -- docker/php/Dockerfile docker/php/cron docker/php/xdebugInstall.sh)" ]]; then
  "${compose[@]}" build php
fi
"${compose[@]}" run --rm --no-deps -T --workdir /web/yii2 --entrypoint php php vendor/bin/deploy-composer.phar check-platform-reqs --no-dev
# make up explicitly applies migrations before starting cron/FPM and recreates PHP/nginx.
make up
ready=0
for attempt in {1..12}; do
  if "${compose[@]}" exec -T --workdir /web/yii2 php php /dev/stdin 'http://nginx/' "$sha" < "$script_dir/check-api.php" \
    && curl --fail --silent --show-error --connect-timeout 5 --max-time 15 "${api_url}health" > "$release/public-health.json" \
    && "${compose[@]}" exec -T php php -r '$d=json_decode(stream_get_contents(STDIN),true);exit(is_array($d)&&($d["status"]??null)==="ok"&&($d["revision"]??null)===$argv[1]&&($d["portalListsVersion"]??null)===1?0:1);' "$sha" < "$release/public-health.json"; then
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
