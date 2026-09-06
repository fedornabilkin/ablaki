#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
compose=(bash deploy/compose.sh)
# Match common/config/main.php: the existing MySQL configuration takes precedence.
database_driver=$("${compose[@]}" run --rm --no-deps -T --entrypoint php php -r 'echo getenv("MYSQL_DB_HOST") && getenv("MYSQL_DB_NAME") ? "mysql" : "pgsql";')
case "$database_driver" in
  mysql) ;; # Use the existing production database; do not start PostgreSQL.
  pgsql) "${compose[@]}" up --detach postgres ;;
  *) printf 'Cannot determine the configured database driver\n' >&2; exit 1 ;;
esac
bash deploy/migrate.sh
test -d yii2/api/runtime
git -c safe.directory="$(pwd)" rev-parse HEAD > yii2/api/runtime/deploy-version.txt
chmod 644 yii2/api/runtime/deploy-version.txt
"${compose[@]}" up --detach --no-deps --force-recreate php nginx
"${compose[@]}" ps
