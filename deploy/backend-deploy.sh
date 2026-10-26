#!/usr/bin/env bash
set -Eeuo pipefail
umask 022

die() { printf '::error title=Backend deployment failed::%s\n' "$*" >&2; exit 1; }
phase='Checking the checkout'
trap 'code=$?; printf "::error title=Backend deployment failed::%s (line %s, exit %s)\n" "$phase" "$LINENO" "$code" >&2' ERR
repo=${1:?Repository path is required}
branch=${2:-master}
target=${3:-production}
expected_sha=${4:-}
[[ "$target" = production || "$target" = test ]] || die 'Invalid deployment environment'
[[ "$target" != production || "$branch" = master ]] || die 'Production only accepts master'
[[ "$repo" = /* && "$repo" != / && -d "$repo/.git" ]] || die 'An existing checkout is required'
cd "$repo"
repo=$(pwd -P)
# Trust only this checkout for this process; keep compatibility with the VPS Git.
git() { command git -c safe.directory="$repo" "$@"; }
git check-ref-format --branch "$branch" >/dev/null
[[ "$(git rev-parse --show-toplevel)" = "$repo" ]] || die 'Repository path mismatch'

phase='Preparing the checkout'
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  stash_message="deploy: $target/$branch $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
  printf '[backend-deploy] stashing local changes: %s\n' "$stash_message"
  git -c user.name='Ablaki deploy' -c user.email='deploy@ablaki.ru' stash push -m "$stash_message"
fi
git fetch --prune origin "$branch"
if [[ -n "$expected_sha" ]]; then
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ && "$(git rev-parse FETCH_HEAD)" = "$expected_sha" ]] || die 'The branch advanced beyond this checked release; use the newer run'
fi
if [[ "$(git symbolic-ref --quiet --short HEAD || true)" != "$branch" ]]; then
  if git show-ref --verify --quiet "refs/heads/$branch"; then
    git checkout "$branch"
  else
    git checkout -b "$branch" "origin/$branch"
  fi
fi
[[ "$(git symbolic-ref --quiet --short HEAD)" = "$branch" ]] || die 'Unable to switch the VPS checkout to the requested branch'

phase='git pull'
printf '[backend-deploy] git pull (%s, %s)\n' "$target" "$branch"
git pull --ff-only origin "${expected_sha:-$branch}"
if [[ -n "$expected_sha" ]]; then
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ && "$(git rev-parse HEAD)" = "$expected_sha" ]] || die 'Checkout no longer matches the checked release; use the newer run'
fi

if [[ -f deploy/php-runtime-upgrade.sh ]]; then
  phase='Installing the verified PHP runtime'
  bash deploy/php-runtime-upgrade.sh "$repo" "$target"
fi

if [[ "$target" = test && "$repo" = /var/code/ablaki && -f /opt/ablaki-backend-test/world-activation.pending ]]; then
  printf '[backend-deploy] World activation is pending; keep writers stopped until the resume step.\n'
  bash deploy/world-open-test.sh "${PROFILE_MODE:-changed}" "${PROFILE_AUTHOR:-fedornabilkin}"
  exit 0
fi

phase='make up'
printf '[backend-deploy] make up\n'
make up
if [[ "$target" = test && "$repo" = /var/code/ablaki && -f deploy/world-open-test.sh ]]; then
  phase='Test readiness and changed profile'
  bash deploy/world-open-test.sh "${PROFILE_MODE:-changed}" "${PROFILE_AUTHOR:-fedornabilkin}"
fi
printf '[backend-deploy] git pull and make up completed\n'
