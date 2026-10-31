#!/bin/sh
# The production PHP image has no procps. Inspect /proc instead of depending on ps.
set -eu
[ "$(cat /proc/1/comm)" = php-fpm ]
# Retire children directly: USR2 on the master escalates to TERM when the
# default process_control_timeout=0, interrupting in-flight requests.
# The master remains available and replaces children as they finish.
# Wait out the configured opcode timestamp interval before retiring old code.
php_info=$(php-fpm -i)
printf '%s\n' "$php_info" | grep -Eq '^opcache.validate_timestamps => On => On$'
printf '%s\n' "$php_info" | grep -Eq '^opcache.revalidate_freq => [012] => [012]$'
sleep 3
workers=''
for process in /proc/[0-9]*; do
  pid=${process#/proc/}
  [ "$pid" != 1 ] || continue
  [ -r "$process/comm" ] || continue
  IFS= read -r command < "$process/comm" || continue
  if [ "$command" = php-fpm ]; then workers="$workers $pid"; fi
done
for pid in $workers; do
  kill -QUIT "$pid" 2>/dev/null || { ! kill -0 "$pid" 2>/dev/null; }
done
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
