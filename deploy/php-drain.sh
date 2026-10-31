#!/bin/sh
# The production PHP image has no procps. Inspect /proc instead of depending on ps.
set -eu
[ "$(cat /proc/1/comm)" = php-fpm ]
workers=''
for process in /proc/[0-9]*; do
  pid=${process#/proc/}
  [ "$pid" != 1 ] || continue
  [ -r "$process/comm" ] || continue
  IFS= read -r command < "$process/comm" || continue
  if [ "$command" = php-fpm ]; then workers="$workers $pid"; fi
done
kill -USR2 1
deadline=$(( $(date +%s) + 180 ))
while :; do
  alive=0
  for pid in $workers; do
    if kill -0 "$pid" 2>/dev/null; then alive=1; fi
  done
  for process in /proc/[0-9]*; do
    [ -r "$process/comm" ] || continue
    IFS= read -r command < "$process/comm" || continue
    if [ "$command" = php ]; then alive=1; fi
  done
  [ "$alive" = 1 ] || break
  [ "$(date +%s)" -lt "$deadline" ] || exit 1
  sleep 1
done
printf 'Old PHP requests and scheduled commands have drained.\n'
