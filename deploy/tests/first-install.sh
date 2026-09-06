#!/usr/bin/env bash
# Real PostgreSQL integration on a fresh, disposable Linux CI project only.
set -Eeuo pipefail
umask 077
die() { printf '[first-install] ERROR: %s\n' "$*" >&2; exit 1; }
pass() { printf 'PASS %s\n' "$*"; }
[[ "${CI:-}" = true && "$(uname -s)" = Linux ]] || die 'Run only in Linux CI'
for command in docker git tar sha256sum mktemp readlink cmp id; do command -v "$command" >/dev/null || die "$command is required"; done
source_root=$(cd "$(dirname "$0")/../.." && pwd)
temp_parent=$(readlink -f "${TMPDIR:-/tmp}")
[[ "$temp_parent" = /* && "$temp_parent" != / ]] || die 'Invalid temporary directory'
test_root=$(mktemp -d "$temp_parent/ablaki-first-install.XXXXXXXX")
test_root=$(readlink -f "$test_root")
[[ "$test_root" = "$temp_parent"/ablaki-first-install.* && "$test_root" != "$temp_parent" ]] || die 'Unsafe fixture path'
checkout="$test_root/checkout"
project="ablaki-ci-$(basename "$test_root" | cut -d. -f2 | tr '[:upper:]' '[:lower:]')"
[[ "$project" =~ ^ablaki-ci-[a-z0-9]{8}$ ]] || die 'Invalid disposable project name'
created=0

# No inherited server credentials, Compose overrides, Docker contexts or .env.
# The explicit socket always selects this Linux runner's local Docker daemon.
ci_command() {
  env -i PATH="$PATH" HOME="$test_root/home" CI=true \
    DOCKER_HOST=unix:///var/run/docker.sock COMPOSE_PROJECT_NAME="$project" \
    COMPOSE_FILE="$checkout/docker-compose.yaml:$checkout/docker-compose.ci.yaml" "$@"
}
compose() {
  ci_command docker compose --project-directory "$checkout" --env-file "$checkout/.env" \
    -p "$project" -f "$checkout/docker-compose.yaml" -f "$checkout/docker-compose.ci.yaml" "$@"
}
cleanup() {
  local result=$?
  trap - EXIT
  if [[ "$created" = 1 && "$project" =~ ^ablaki-ci-[a-z0-9]{8}$ \
      && "$checkout" = "$test_root/checkout" && -f "$checkout/docker-compose.ci.yaml" ]]; then
    # Only the random project whose absence we checked before creating it.
    compose down --volumes --remove-orphans --timeout 10 || result=1
  fi
  if [[ "$test_root" = "$temp_parent"/ablaki-first-install.* \
      && "$test_root" != "$temp_parent" && "$(readlink -f "$test_root")" = "$test_root" ]]; then
    rm -rf -- "$test_root" || result=1
  fi
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
mkdir -p "$checkout" "$test_root/home"
ci_command docker compose version >/dev/null
ci_command docker image inspect ablaki-php-ci >/dev/null

# Archive tracked source only: no working-tree .env, runtime, uploads or vendor.
git -C "$source_root" archive --format=tar HEAD | tar -xf - -C "$checkout" --no-same-owner
[[ ! -e "$checkout/.env" && ! -e "$checkout/yii2/vendor" ]] || die 'Tracked source unexpectedly contains local environment/dependencies'
[[ -s "$source_root/release/vendor.tar.gz" && -s "$source_root/release/vendor.tar.gz.sha256" ]] || die 'Missing checked vendor artifact'
(cd "$source_root/release" && sha256sum -c vendor.tar.gz.sha256)
tar -xzf "$source_root/release/vendor.tar.gz" -C "$checkout/yii2" --no-same-owner
[[ -f "$checkout/yii2/vendor/autoload.php" ]] || die 'Incomplete vendor artifact'
[[ "$(tr -d '\r\n' < "$checkout/yii2/commit.txt")" = "$(git -C "$source_root" rev-parse HEAD)" ]] || die 'Vendor artifact belongs to another commit'
[[ "$(sha256sum "$checkout/yii2/composer.lock" | cut -d' ' -f1)" = "$(tr -d '\r\n' < "$checkout/yii2/composer.lock.sha256")" ]] || die 'Vendor lock mismatch'

cat > "$checkout/.env" <<ENV
APP_ENVIRONMENT=test
COMPOSE_PROJECT_NAME=$project
COMPOSE_SUBNET=172.30.$((10 + $$ % 230)).0/24
APP_BIND_IP=127.0.0.1
PORT_NGINX_API=0
PORT_NGINX_FRONT=0
PORT_NGINX_ADMIN=0
PG_DB_PORT=0
PG_DB_HOST=postgres
PG_DB_NAME=ablaki_ci
PG_DB_USER=ablaki_ci
PG_DB_PASSWORD=disposable_ci_fixture_only
YII_ENV=prod
YII_DEBUG=0
ENV
cat > "$checkout/docker-compose.ci.yaml" <<YAML
services:
  php:
    image: ablaki-php-ci
    user: "$(id -u):$(id -g)"
YAML
for application in api backend frontend console; do
  mkdir -p "$checkout/yii2/$application/runtime"
  if [[ "$application" != console ]]; then mkdir -p "$checkout/yii2/$application/web/assets"; fi
done
compose config --quiet
for resource in container network volume; do
  case "$resource" in
    container) existing=$(ci_command docker ps -aq --filter "label=com.docker.compose.project=$project") ;;
    network) existing=$(ci_command docker network ls -q --filter "label=com.docker.compose.project=$project") ;;
    volume) existing=$(ci_command docker volume ls -q --filter "label=com.docker.compose.project=$project") ;;
  esac
  [[ -z "$existing" ]] || die 'Disposable project already exists; refusing to use or clean it'
done
created=1
compose up --detach --no-deps postgres
ready=0
for attempt in {1..30}; do
  if compose exec -T postgres pg_isready -h 127.0.0.1 -U ablaki_ci -d ablaki_ci >/dev/null 2>&1; then ready=1; break; fi
  sleep 2
done
[[ "$ready" = 1 ]] || die 'Disposable PostgreSQL did not become ready'
psql() { compose exec -T postgres psql -X -v ON_ERROR_STOP=1 -U ablaki_ci -d "${1:-ablaki_ci}" -At; }
[[ "$(printf 'SELECT current_database();\n' | psql)" = ablaki_ci ]] || die 'Unexpected fixture database'
[[ "$(printf "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';\n" | psql)" = 0 ]] || die 'Fixture database is not empty'

(cd "$checkout" && ci_command bash deploy/migrate.sh)
psql <<'SQL'
DO $$
DECLARE name text;
BEGIN
  FOREACH name IN ARRAY ARRAY['user','profile','persone','history_balance','history_rating',
    'credit_transfer','credit_exchange','game_orel','game_saper','forum_theme','forum_comment',
    'forum_comment_gift','user_presence','auth_item','auth_assignment','bind_uids','redirect'] LOOP
    IF to_regclass(format('public.%I', name)) IS NULL THEN RAISE EXCEPTION 'Missing table: %', name; END IF;
  END LOOP;
  IF (SELECT count(*) FROM pg_constraint WHERE contype='f' AND convalidated
      AND conname IN ('fk-forum-gift-comment','fk-forum-gift-user','fk-forum-gift-recipient','fk-user_presence-user')) <> 4
    THEN RAISE EXCEPTION 'New PostgreSQL foreign keys are missing or unvalidated'; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE contype='f' AND convalidated
      AND conrelid='persone'::regclass AND confrelid='public.user'::regclass)
    THEN RAISE EXCEPTION 'Base user relation is missing'; END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public'
      AND table_name='persone' AND column_name='description')
    THEN RAISE EXCEPTION 'Person description migration is missing'; END IF;
  IF (SELECT count(*) FROM migration WHERE version IN ('m260905_120000_create_forum_comment_gift_table',
      'm260905_180000_create_user_presence_table')) <> 2
    THEN RAISE EXCEPTION 'New migrations were not recorded'; END IF;
  BEGIN
    INSERT INTO user_presence (user_id,last_seen_at) VALUES (900002,1);
    RAISE EXCEPTION 'An orphan presence row was accepted';
  EXCEPTION WHEN foreign_key_violation THEN NULL;
  END;
END $$;
SQL
pass 'fresh PostgreSQL migrations create base/new tables and enforce foreign keys'
printf 'SELECT version,apply_time FROM migration ORDER BY version;\n' | psql > "$test_root/migrations-before.txt"
(cd "$checkout" && ci_command bash deploy/migrate.sh)
printf 'SELECT version,apply_time FROM migration ORDER BY version;\n' | psql > "$test_root/migrations-after.txt"
cmp "$test_root/migrations-before.txt" "$test_root/migrations-after.txt"
pass 'repeated migration run leaves the complete migration history unchanged'

psql <<'SQL'
INSERT INTO public."user" (id,username,email,password_hash,auth_key,created_at,updated_at)
VALUES (900001,'ci-fixture','ci-fixture@example.invalid','not-a-valid-login-password',repeat('c',32),1700000000,1700000000);
INSERT INTO user_presence (user_id,last_seen_at) VALUES (900001,1700000000);
SQL
compose exec -T postgres pg_dump -U ablaki_ci -d ablaki_ci --format=custom > "$test_root/fixture.dump"
test -s "$test_root/fixture.dump"
compose exec -T postgres pg_restore --list < "$test_root/fixture.dump" > /dev/null
compose exec -T postgres createdb -U ablaki_ci --template=template0 ablaki_ci_restored
compose exec -T postgres pg_restore -U ablaki_ci -d ablaki_ci_restored --exit-on-error --single-transaction < "$test_root/fixture.dump"
fixture_query='SELECT u.username,u.email,p.last_seen_at FROM public."user" u JOIN user_presence p ON p.user_id=u.id WHERE u.id=900001;'
[[ "$(printf '%s\n' "$fixture_query" | psql ablaki_ci_restored)" = 'ci-fixture|ci-fixture@example.invalid|1700000000' ]] || die 'Restored fixture data differs'
printf 'SELECT version,apply_time FROM migration ORDER BY version;\n' | psql ablaki_ci_restored > "$test_root/migrations-restored.txt"
cmp "$test_root/migrations-after.txt" "$test_root/migrations-restored.txt"
[[ "$(printf "SELECT count(*) FROM pg_constraint WHERE contype='f' AND convalidated AND conname IN ('fk-forum-gift-comment','fk-forum-gift-user','fk-forum-gift-recipient','fk-user_presence-user');\n" | psql ablaki_ci_restored)" = 4 ]] || die 'Restored foreign keys differ'
pass 'custom PostgreSQL dump restores fixture data, migration history and validated foreign keys'
