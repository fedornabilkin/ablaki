#!/usr/bin/env bash
# Only temporary fixtures: no SSH, Docker daemon or real database.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/bin"
export TEST_LOG TEST_REPO TEST_FAIL TEST_BRANCH
cat > "$test_root/bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
[[ "$1" = -c && "$2" = "safe.directory=$TEST_REPO" ]]
while [[ "$1" = -c ]]; do shift 2; done
printf 'git %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'check-ref-format --branch') [[ "$TEST_FAIL" != invalid_branch ]] ;;
  'rev-parse --show-toplevel') printf '%s\n' "$TEST_REPO" ;;
  'status --porcelain')
    [[ "$TEST_FAIL" != dirty ]] || printf ' M local.php\n'
    ;;
  'stash push') ;;
  'fetch --prune') ;;
  'show-ref --verify') exit 1 ;;
  'checkout -b') printf '%s\n' "$3" > "$TEST_REPO/current-branch" ;;
  'checkout master') printf '%s\n' "$2" > "$TEST_REPO/current-branch" ;;
  'checkout feature/test') printf '%s\n' "$2" > "$TEST_REPO/current-branch" ;;
  'symbolic-ref --quiet')
    if [[ -f "$TEST_REPO/current-branch" ]]; then cat "$TEST_REPO/current-branch"; else printf '%s\n' "$TEST_BRANCH"; fi
    ;;
  'pull --ff-only')
    expected_branch="$TEST_BRANCH"
    [[ ! -f "$TEST_REPO/current-branch" ]] || expected_branch="$(cat "$TEST_REPO/current-branch")"
    [[ "$3" = origin && "$4" = "$expected_branch" ]]
    [[ "$(umask)" = 0022 ]]
    [[ "$TEST_FAIL" != pull ]] || exit 23
    printf 'updated code\n' > "$TEST_REPO/code.txt"
    ;;
  *) echo "Unexpected Git command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/bin/make" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'make %s\n' "$*" >> "$TEST_LOG"
[[ "$*" = up ]]
[[ -f "$TEST_REPO/code.txt" ]] || { echo 'make ran before git pull' >&2; exit 1; }
[[ "$TEST_FAIL" != make ]] || exit 31
MOCK
cat > "$test_root/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
grep -Fxq 'make up' "$TEST_LOG" || { echo 'Docker ran before make up' >&2; exit 1; }
echo 'The deploy wrapper must not call Docker directly' >&2
exit 1
MOCK
chmod +x "$test_root/bin/"*
export PATH="$test_root/bin:$PATH"

for scenario in production test dirty pull make wrong_branch invalid_branch production_branch; do
  TEST_FAIL=$scenario
  TEST_REPO="$test_root/$scenario"
  mkdir -p "$TEST_REPO/.git" "$TEST_REPO/yii2/vendor"
  TEST_REPO=$(cd "$TEST_REPO" && pwd -P)
  TEST_LOG="$TEST_REPO/commands.log"
  TEST_BRANCH=master
  target=production
  branch=master
  if [[ "$scenario" = test ]]; then target=test; branch=feature/test; TEST_BRANCH=$branch; fi
  if [[ "$scenario" = wrong_branch ]]; then TEST_BRANCH=another; fi
  if [[ "$scenario" = production_branch ]]; then branch=feature/test; fi
  printf '# Local configuration\nDB_FIXTURE=preserved\n' > "$TEST_REPO/.env"
  cp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  printf 'existing vendor\n' > "$TEST_REPO/yii2/vendor/autoload.php"
  : > "$TEST_LOG"
  status=0
  bash "$source_root/deploy/backend-deploy.sh" "$TEST_REPO" "$branch" "$target" > "$TEST_REPO/output.log" 2>&1 || status=$?
  case "$scenario" in
    production|test|dirty)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq "git pull --ff-only origin $branch" "$TEST_LOG"
      grep -Fxq 'make up' "$TEST_LOG"
      if [[ "$scenario" = dirty ]]; then grep -Eq '^git stash push -m deploy: production/master ' "$TEST_LOG"; fi
      ! grep -q '^docker' "$TEST_LOG"
      ;;
    pull)
      [[ "$status" = 23 ]]
      ! grep -q '^make\|^docker' "$TEST_LOG"
      ;;
    make)
      [[ "$status" = 31 ]]
      ! grep -q '^docker' "$TEST_LOG"
      ;;
    invalid_branch|production_branch)
      [[ "$status" != 0 ]]
      ! grep -q '^git pull\|^make\|^docker' "$TEST_LOG"
      ;;
    wrong_branch)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq "git pull --ff-only origin $branch" "$TEST_LOG"
      grep -Fxq 'make up' "$TEST_LOG"
      ;;
  esac
  cmp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  [[ "$(cat "$TEST_REPO/yii2/vendor/autoload.php")" = 'existing vendor' ]]
  ! grep -Eq 'stop |down |build |force-recreate|up --detach postgres|getenv|check-platform|reset|pg_dump|pg_restore' "$TEST_LOG"
  printf 'PASS deployment scenario: %s\n' "$scenario"
done

# The runtime transition keeps the old vendor/image until the new FPM is ready.
cat > "$test_root/bin/flock" <<'MOCK'
#!/usr/bin/env bash
exit 0
MOCK
cat > "$test_root/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
case "$1" in
  container) [[ "$TEST_FAIL" = runtime_test ]] ;;
  inspect)
    case "$3" in
      '{{.Image}}') echo sha256:previous ;;
      '{{.Config.Image}}') echo fixture_php ;;
      '{{.State.Running}}') echo true ;;
      '{{index .Config.Labels "com.docker.compose.oneoff"}}')
        if [[ "$4" = worker-id ]]; then echo True; else echo False; fi ;;
      *) echo test ;;
    esac ;;
  run) if [[ "$TEST_FAIL" = runtime_warm ]]; then echo 8.3.36; else echo 7.3.2; fi ;;
  tag|start|stop) ;;
  *) exit 1 ;;
esac
MOCK
cat > "$test_root/bin/docker-compose" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'compose %s\n' "$*" >> "$TEST_LOG"
case "$1" in
  ps)
    echo "$3-id"
    if [[ "$TEST_FAIL" = runtime_multiple && "$3" = php ]]; then echo worker-id; fi
    ;;
  build) [[ "$TEST_FAIL" != runtime_build ]] || exit 41 ;;
  stop|up) ;;
  exec) [[ "$TEST_FAIL" != runtime_start ]] || exit 43 ;;
  run)
    if [[ "$*" = *' composer php install '* ]]; then
      args=("$@")
      for ((i=0; i<${#args[@]}; i++)); do
        if [[ "${args[i]}" = -v ]]; then vendor_path=${args[i+1]%:/web/yii2/vendor}; break; fi
      done
      mkdir -p "$vendor_path"
      printf 'new vendor\n' > "$vendor_path/autoload.php"
      [[ "$TEST_FAIL" != runtime_install ]] || exit 42
    fi ;;
  *) exit 1 ;;
esac
MOCK
chmod +x "$test_root/bin/"*
for scenario in runtime_warm runtime_build runtime_install runtime_start runtime_production runtime_test runtime_multiple; do
  TEST_FAIL=$scenario
  TEST_REPO="$test_root/$scenario"
  mkdir -p "$TEST_REPO/.git" "$TEST_REPO/yii2/vendor" "$TEST_REPO/.php-runtime-upgrade" "$TEST_REPO/docker"
  TEST_REPO=$(cd "$TEST_REPO" && pwd -P)
  TEST_LOG="$TEST_REPO/commands.log"
  cp -R "$source_root/docker/php" "$source_root/docker/composer" "$TEST_REPO/docker/"
  cp "$source_root/yii2/composer.json" "$source_root/yii2/composer.lock" "$TEST_REPO/yii2/"
  printf 'existing vendor\n' > "$TEST_REPO/yii2/vendor/autoload.php"
  printf 'local environment\n' > "$TEST_REPO/.env"
  (
    cd "$TEST_REPO"
    sha256sum docker/php/Dockerfile docker/php/runtime.ini docker/php/security-fpm.conf docker/php/entrypoint.sh docker/composer/Dockerfile docker/composer/entrypoint.sh yii2/composer.json yii2/composer.lock | sha256sum | cut -d' ' -f1 > .php-runtime-upgrade/active
  )
  target=production
  [[ "$scenario" != runtime_test ]] || target=test
  status=0
  bash "$source_root/deploy/php-runtime-upgrade.sh" "$TEST_REPO" "$target" > "$TEST_REPO/output.log" 2>&1 || status=$?
  case "$scenario" in
    runtime_warm)
      [[ "$status" = 0 ]]
      ! grep -q '^compose build\|^compose stop\|^compose run' "$TEST_LOG"
      ;;
    runtime_build)
      [[ "$status" = 41 ]]
      ! grep -q '^compose stop\|^compose run' "$TEST_LOG"
      ;;
    runtime_install)
      [[ "$status" = 42 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      ! grep -q '^compose stop\|^docker stop' "$TEST_LOG"
      ;;
    runtime_start)
      [[ "$status" = 43 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      grep -Fxq 'docker tag sha256:previous fixture_php' "$TEST_LOG"
      grep -Fxq 'compose up -d --no-deps nginx' "$TEST_LOG"
      ;;
    *)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      [[ "$(cat "$TEST_REPO/yii2/vendor/autoload.php")" = 'new vendor' ]]
      grep -Fxq 'compose stop -t 60 nginx php composer' "$TEST_LOG"
      if [[ "$target" = production ]]; then
        grep -q 'composer php install .*--no-dev' "$TEST_LOG"
      else
        ! grep -q -- '--no-dev' "$TEST_LOG"
        grep -Fxq 'docker stop --time 60 ablaki-world-worker-test' "$TEST_LOG"
      fi
      ;;
  esac
  if [[ "$scenario" = runtime_warm || "$scenario" = runtime_build || "$scenario" = runtime_install || "$scenario" = runtime_start ]]; then
    [[ "$(cat "$TEST_REPO/yii2/vendor/autoload.php")" = 'existing vendor' ]]
  fi
  [[ "$(cat "$TEST_REPO/.env")" = 'local environment' ]]
  ! grep -Eq 'pg_dump|mysqldump|pg_restore|down |reset|stop.*postgres|stop.*mysql' "$TEST_LOG"
  printf 'PASS deployment scenario: %s\n' "$scenario"
done
