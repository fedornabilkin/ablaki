#!/usr/bin/env bash
# No Docker daemon, SSH, live checkout, or database: all external commands are fixtures.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/bin"
export TEST_LOG TEST_REPO TEST_HEAD TEST_SHA TEST_OLD TEST_FAIL TEST_FINGERPRINT TEST_BRANCH_FILE TEST_TARGET
TEST_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
TEST_OLD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
TEST_FINGERPRINT=$(printf 'cluster/database' | sha256sum | cut -d' ' -f1)
cat > "$test_root/bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
if [[ "${1:-}" = -c ]]; then
  [[ "$2" = "safe.directory=$TEST_REPO" ]] || { echo 'Unexpected trusted Git directory' >&2; exit 1; }
  shift 2
else
  echo 'Deployment Git must trust exactly the selected checkout' >&2
  exit 1
fi
printf 'git %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'rev-parse --show-toplevel') printf '%s\n' "$TEST_REPO" ;;
  'symbolic-ref --quiet') if [[ "$TEST_FAIL" = branch ]]; then echo work; else cat "$TEST_BRANCH_FILE"; fi ;;
  'rev-parse HEAD') cat "$TEST_HEAD" ;;
  'rev-parse origin/master'|'rev-parse origin/feature/test') if [[ "$TEST_FAIL" = stale ]]; then echo cccccccccccccccccccccccccccccccccccccccc; else echo "$TEST_SHA"; fi ;;
  'diff --quiet') [[ "$TEST_FAIL" != dirty ]] ;;
  'diff --cached'|'diff --name-only'|'fetch --no-tags'|'check-ref-format --branch') ;;
  'merge-base --is-ancestor') [[ "$TEST_FAIL" != diverged ]] ;;
  'show-ref --verify') [[ "$TEST_FAIL" != new_branch ]] ;;
  'checkout feature/test') printf 'feature/test\n' > "$TEST_BRANCH_FILE" ;;
  'checkout -b') printf '%s\n' "$3" > "$TEST_BRANCH_FILE" ;;
  'merge --ff-only')
    [[ "$(umask)" = 0022 ]] || { echo 'Checkout must be readable by PHP/nginx' >&2; exit 1; }
    printf '%s\n' "$TEST_SHA" > "$TEST_HEAD"
    ;;
  'show '*) cat "$TEST_REPO/yii2/composer.lock" ;;
  *) echo "Unexpected git command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
if [[ "$1" = inspect ]]; then
  case "$*" in
    *working_dir*) if [[ "$TEST_FAIL" = wrong_checkout ]]; then echo /another/checkout; else echo "$TEST_REPO"; fi ;;
    *Mounts*) echo fixture-pg-volume ;;
    *) if [[ "$TEST_TARGET" = production || "$TEST_FAIL" = wrong_project ]]; then echo ablaki-production; else echo ablaki; fi ;;
  esac
  exit 0
fi
if [[ "$1 $2" = 'volume inspect' ]]; then
  if [[ "$TEST_FAIL" = shared_volume ]]; then echo another-project;
  elif [[ "$TEST_TARGET" = production ]]; then echo ablaki-production; else echo ablaki; fi
  exit 0
fi
[[ "$1" = compose ]] || exit 1
shift
case "$1" in
  version|config|stop|start|up|build) exit 0 ;;
  ps) if [[ "$*" = 'ps -q php' || "$*" = 'ps -q nginx' ]]; then echo fixture-app; fi; exit 0 ;;
  run)
    if [[ "$*" = *'getenv("APP_ENVIRONMENT")'* && "$TEST_FAIL" = wrong_environment ]]; then exit 1; fi
    if [[ "$*" = *'getenv("MYSQL_DB_HOST")'* ]]; then
      if [[ "$TEST_TARGET" = production ]]; then echo mysql; else echo pgsql; fi
      exit 0
    fi
    if [[ "$*" = *'migrate/up'* && "$TEST_FAIL" = migration ]]; then exit 1; fi
    if [[ "$*" = *'--entrypoint php php' ]]; then
      cat > /dev/null
      if [[ "$TEST_FAIL" = identity ]]; then echo wrong; else printf '%s' "$TEST_FINGERPRINT"; fi
    fi
    exit 0
    ;;
  exec)
    if [[ "$*" = *pg_control_system* ]]; then printf 'cluster/database\n'
    elif [[ "$*" = *pg_dump* ]]; then [[ "$TEST_FAIL" != backup ]]; echo 'fixture dump'
    elif [[ "$*" = *pg_restore* ]]; then cat > /dev/null
    else cat > /dev/null; [[ "$TEST_FAIL" != health ]]
    fi
    ;;
  *) echo "Unexpected compose command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/bin/make" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'make %s\n' "$*" >> "$TEST_LOG"
[[ "$*" = up ]]
bash deploy/start.sh
MOCK
cat > "$test_root/bin/curl" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'curl %s\n' "$*" >> "$TEST_LOG"
[[ "$TEST_FAIL" != health ]]
printf '{"status":"ok","revision":"%s","portalListsVersion":1,"environment":"%s"}' "$TEST_SHA" "$TEST_TARGET"
MOCK
cat > "$test_root/bin/flock" <<'MOCK'
#!/usr/bin/env bash
printf 'flock %s\n' "$*" >> "$TEST_LOG"
[[ "$TEST_FAIL" != lock ]]
MOCK
printf '#!/usr/bin/env bash\nexit 0\n' > "$test_root/bin/sleep"
chmod +x "$test_root/bin/"*
export PATH="$test_root/bin:$PATH"

setup_case() {
  TEST_FAIL=$1
  TEST_REPO="$test_root/$1"
  TEST_DEPLOY="$test_root/$1-deploy"
  mkdir -p "$TEST_DEPLOY/incoming/$TEST_SHA" "$TEST_REPO/.git" "$TEST_REPO/yii2/vendor" "$TEST_REPO/yii2/api/runtime" "$TEST_REPO/deploy" "$TEST_REPO/package/vendor/bin"
  TEST_REPO=$(cd "$TEST_REPO" && pwd)
  TEST_DEPLOY=$(cd "$TEST_DEPLOY" && pwd)
  TEST_LOG="$TEST_REPO/commands.log"
  TEST_HEAD="$TEST_REPO/head.txt"
  TEST_BRANCH_FILE="$TEST_REPO/branch.txt"
  TEST_TARGET=production
  printf 'master\n' > "$TEST_BRANCH_FILE"
  printf '# Existing environment must remain intact\nDB_FIXTURE=preserved\n' > "$TEST_REPO/.env"
  : > "$TEST_LOG"
  printf '%s\n' "$TEST_OLD" > "$TEST_HEAD"
  printf 'old vendor' > "$TEST_REPO/yii2/vendor/previous.txt"
  printf '{"locked":true}\n' > "$TEST_REPO/yii2/composer.lock"
  cp "$source_root/deploy/"{compose,migrate,start}.sh "$TEST_REPO/deploy/"
  incoming="$TEST_DEPLOY/incoming/$TEST_SHA"
  cp "$source_root/deploy/"{backend-deploy.sh,database-fingerprint.php,check-api.php} "$incoming/"
  printf 'new vendor' > "$TEST_REPO/package/vendor/autoload.php"
  printf 'composer fixture' > "$TEST_REPO/package/vendor/bin/deploy-composer.phar"
  printf '%s\n' "$TEST_SHA" > "$TEST_REPO/package/commit.txt"
  sha256sum "$TEST_REPO/yii2/composer.lock" | cut -d' ' -f1 > "$TEST_REPO/package/composer.lock.sha256"
  tar -C "$TEST_REPO/package" -czf "$incoming/vendor.tar.gz" vendor commit.txt composer.lock.sha256
  (cd "$incoming" && sha256sum vendor.tar.gz > vendor.tar.gz.sha256)
  if [[ "$1" = checksum ]]; then printf tampered >> "$incoming/vendor.tar.gz"; fi
}
expect_log() { grep -Fq -- "$1" "$TEST_LOG" || { echo "Missing operation: $1" >&2; exit 1; }; }
reject_log() { if grep -Fq -- "$1" "$TEST_LOG"; then echo "Forbidden operation: $1" >&2; exit 1; fi; }
before() {
  local first second
  first=$(grep -nF -- "$1" "$TEST_LOG" | head -n 1 | cut -d: -f1)
  second=$(grep -nF -- "$2" "$TEST_LOG" | head -n 1 | cut -d: -f1)
  [[ "$first" -lt "$second" ]] || { echo "Wrong order: $1 / $2" >&2; exit 1; }
}
for scenario in success stale dirty branch lock checksum migration health root_inside root_parent root_missing wrong_archive test_branch new_branch diverged wrong_checkout wrong_environment test_production_api production_branch; do
  setup_case "$scenario"
  state_arg="$TEST_DEPLOY"
  archive_arg="$incoming/vendor.tar.gz"
  branch_arg=master
  api_arg='https://api.example.test/'
  case "$scenario" in
    root_inside) state_arg="$TEST_REPO/.deploy"; mkdir "$state_arg" ;;
    root_parent) state_arg="$test_root" ;;
    root_missing) state_arg="$test_root/absent-deploy" ;;
    wrong_archive) archive_arg="$TEST_REPO/vendor.tar.gz"; cp "$incoming/vendor.tar.gz" "$archive_arg"; cp "$incoming/vendor.tar.gz.sha256" "$archive_arg.sha256" ;;
    test_branch|new_branch) TEST_TARGET=test; branch_arg=feature/test ;;
    test_production_api) TEST_TARGET=test; api_arg='https://api.ablakin.ru/' ;;
    production_branch) branch_arg=feature/test ;;
  esac
  status=0
  bash "$incoming/backend-deploy.sh" "$TEST_REPO" "$TEST_SHA" "$archive_arg" "$api_arg" "$state_arg" "$branch_arg" "$TEST_TARGET" > "$TEST_REPO/output.log" 2>&1 || status=$?
  case "$scenario" in
    success|test_branch|new_branch)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      [[ "$(cat "$TEST_DEPLOY/current")" = "$TEST_SHA" ]]
      [[ "$(cat "$TEST_REPO/yii2/api/runtime/deploy-version.txt")" = "$TEST_SHA" ]]
      before 'stop nginx php composer' 'git merge --ff-only'
      before 'check-platform-reqs' 'migrate/up'
      before 'migrate/up' 'up --detach --no-deps --force-recreate php nginx'
      [[ -n "$(find "$TEST_DEPLOY/releases" -path '*/previous-vendor/previous.txt' -print -quit)" ]]
      [[ ! -e "$TEST_DEPLOY/backups" ]]
      if [[ "$TEST_TARGET" = production ]]; then
        expect_log 'check-platform-reqs --no-dev'
        reject_log 'up --detach postgres'
      else
        reject_log 'check-platform-reqs --no-dev'
        before 'up --detach postgres' 'migrate/up'
      fi
      [[ ! -e "$TEST_REPO/.git/ablaki-deploy" ]]
      [[ "$(cat "$TEST_BRANCH_FILE")" = "$branch_arg" ]]
      grep -Fxq 'DB_FIXTURE=preserved' "$TEST_REPO/.env"
      grep -Fxq "APP_ENVIRONMENT=$TEST_TARGET" "$TEST_REPO/.env"
      ;;
    stale)
      [[ "$status" = 0 ]]; reject_log 'stop nginx'; reject_log 'git merge --ff-only'
      ;;
    dirty|branch|lock|checksum|root_inside|root_parent|root_missing|wrong_archive|diverged|wrong_checkout|wrong_environment|test_production_api|production_branch)
      [[ "$status" != 0 ]]; reject_log 'stop nginx'; reject_log 'git merge --ff-only'
      ;;
    migration|health)
      [[ "$status" != 0 ]]; expect_log 'stop nginx php'; [[ ! -f "$TEST_DEPLOY/current" ]]
      reject_log 'git reset'; reject_log 'migrate/down'
      if [[ "$scenario" = migration ]]; then reject_log 'up --detach --no-deps --force-recreate php nginx'; fi
      ;;
  esac
  reject_log 'down --volumes'
  reject_log 'make init'
  reject_log 'pg_dump'
  reject_log 'pg_restore'
  reject_log 'pg_control_system'
  printf 'PASS deployment scenario: %s\n' "$scenario"
done
