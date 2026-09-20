#!/usr/bin/env bash
# Preserve the owner's previously untracked design document before this release starts tracking it.
set -Eeuo pipefail
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
document=docs/plan/mvp-ablaki-craft.md
if [[ -f "$document" ]] && ! git -c safe.directory=/var/code/ablaki ls-files --error-unmatch -- "$document" >/dev/null 2>&1; then
  [[ ! -L "$document" ]]
  git_directory=$(git -c safe.directory=/var/code/ablaki rev-parse --absolute-git-dir)
  backup_directory="$git_directory/craft-plan-backups"
  mkdir -p "$backup_directory"
  backup="$backup_directory/mvp-ablaki-craft-$(date -u +%Y%m%dT%H%M%S)-$$.md"
  [[ ! -e "$backup" ]]
  mv -- "$document" "$backup"
  [[ -f "$backup" && ! -e "$document" ]]
  echo "Preserved the existing craft plan in $backup"
fi
