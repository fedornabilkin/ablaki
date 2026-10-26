#!/bin/sh
# Finish the current PHP batch before exiting; interrupt only the idle sleep.
set -u
stopping=0
child=''
idle=0
stop() {
    stopping=1
    if [ "$idle" = 1 ] && [ -n "$child" ]; then kill "$child" 2>/dev/null || true; fi
}
trap stop TERM INT QUIT
while [ "$stopping" = 0 ]; do
    idle=0
    php /web/yii2/yii world-worker/run 200 &
    child=$!
    while kill -0 "$child" 2>/dev/null; do wait "$child" || true; done
    child=''
    [ "$stopping" = 0 ] || break
    idle=1
    sleep 5 &
    child=$!
    [ "$stopping" = 0 ] || kill "$child" 2>/dev/null || true
    wait "$child" || true
    child=''
done
exit 0
