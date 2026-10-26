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

# Test release activation uses command fixtures, never a real database or Docker.
mkdir -p "$test_root/world-bin"
export TEST_DEPLOY_ROOT TEST_WORLD_FAIL TEST_WORKER_HASH
cat > "$test_root/world-bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
case "$1" in
  symbolic-ref) echo test ;;
  ls-files) printf '.installer\0' ;;
  rev-parse) printf '%040d\n' 1 ;;
  *) exit 1 ;;
esac
MOCK
cat > "$test_root/world-bin/flock" <<'MOCK'
#!/usr/bin/env bash
[[ "$*" = '-n 9' && "$TEST_WORLD_FAIL" != lock ]]
MOCK
cat > "$test_root/world-bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'docker %s\n' "$*" >> "$TEST_LOG"
case "$1 $2" in
  'container inspect') [[ -f "$TEST_REPO/worker" ]] ;;
  'inspect -f')
    case "$3" in
      *com.docker.compose.oneoff*) echo False ;;
      '{{.Image}}') echo sha256:fixture ;;
      *'|{{.State.Running}}|'*) echo "sha256:fixture|true|$TEST_WORKER_HASH" ;;
      '{{.State.Running}}') [[ "$TEST_WORLD_FAIL" != worker ]] && echo true || echo false ;;
      *) [[ "$TEST_WORLD_FAIL" != label ]] && echo test || echo unrelated ;;
    esac ;;
  'stop --time'|'update --restart') ;;
  'rm ablaki-world-worker-test') rm -f "$TEST_REPO/worker" ;;
  *) echo "Unexpected Docker command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/world-bin/docker-compose" <<'MOCK'
#!/usr/bin/env bash
set -eu
printf 'compose %s\n' "$*" >> "$TEST_LOG"
case "$*" in
  *'release/test-state')
    [[ "$TEST_WORLD_FAIL" != state ]] || exit 2
    echo '{"ready":true,"schema":43,"profile":4}' ;;
  *'release/test-install')
    [[ -e "$TEST_DEPLOY_ROOT/world-activation.pending" ]]
    [[ "$TEST_WORLD_FAIL" != setup ]] || exit 23 ;;
  *'release/test-profile '*) [[ "$TEST_WORLD_FAIL" != profile ]] || exit 25 ;;
  *'world-worker/run 200') [[ "$TEST_WORLD_FAIL" != handlers ]] || exit 24 ;;
  'stop -t 60 nginx php'|'up -d --no-deps php nginx') ;;
  'ps -q php') echo php-id ;;
  'port nginx 80') echo 0.0.0.0:3180 ;;
  'run -d --no-deps --name ablaki-world-worker-test '*) touch "$TEST_REPO/worker" ;;
  *) echo "Unexpected Compose command: $*" >&2; exit 1 ;;
esac
MOCK
cat > "$test_root/world-bin/curl" <<'MOCK'
#!/usr/bin/env bash
set -eu
[[ "$TEST_WORLD_FAIL" != http ]] || exit 22
output=''
write=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o|--output) output=$2; shift ;;
    --write-out) write=true; shift ;;
  esac
  shift
done
[[ -z "$output" ]] || printf '{"users":1}\n' > "$output"
[[ "$write" != true ]] || printf 401
MOCK
chmod +x "$test_root/world-bin/"*
export PATH="$test_root/world-bin:$PATH"
for scenario in cold warm resume changed profile_changed setup handlers worker label lock state profile http; do
  TEST_WORLD_FAIL=$scenario
  TEST_REPO="$test_root/world-$scenario/repo"
  TEST_DEPLOY_ROOT="$test_root/world-$scenario/deploy"
  mkdir -p "$TEST_REPO/yii2/console" "$TEST_REPO/yii2/common/modules/world/data/economy" "$TEST_DEPLOY_ROOT"
  TEST_REPO=$(cd "$TEST_REPO" && pwd -P)
  TEST_DEPLOY_ROOT=$(cd "$TEST_DEPLOY_ROOT" && pwd -P)
  printf 'installer v1\n' > "$TEST_REPO/.installer"
  printf '# Preserved settings\nDB_FIXTURE=preserved\nWORLD_READ=1\n' > "$TEST_REPO/.env"
  cp "$TEST_REPO/.env" "$TEST_REPO/env-before"
  cp "$source_root/yii2/console/world-worker-loop.sh" "$TEST_REPO/yii2/console/"
  touch "$TEST_REPO/yii2/common/modules/world/Module.php" "$TEST_REPO/worker"
  printf 'profile\n' > "$TEST_REPO/yii2/common/modules/world/data/economy/closed-test-v1.json"
  TEST_WORKER_HASH=$(sha256sum "$TEST_REPO/yii2/console/world-worker-loop.sh" | cut -d' ' -f1)
  TEST_LOG="$TEST_REPO/commands.log"
  : > "$TEST_LOG"
  (cd "$TEST_REPO"; sha256sum .installer | sha256sum | cut -d' ' -f1) > "$TEST_DEPLOY_ROOT/world-installation.sha256"
  sha256sum "$TEST_REPO/yii2/common/modules/world/data/economy/closed-test-v1.json" | cut -d' ' -f1 > "$TEST_DEPLOY_ROOT/world-profile.sha256"
  case "$scenario" in
    cold|setup|handlers|worker|profile) rm "$TEST_DEPLOY_ROOT/world-installation.sha256" ;;
    resume) touch "$TEST_DEPLOY_ROOT/world-activation.pending" ;;
    changed) printf 'installer v2\n' > "$TEST_REPO/.installer" ;;
    profile_changed) echo previous > "$TEST_DEPLOY_ROOT/world-profile.sha256" ;;
  esac
  sed -e "s@/var/code/ablaki@$TEST_REPO@g" -e "s@/opt/ablaki-backend-test@$TEST_DEPLOY_ROOT@g" \
    "$source_root/deploy/world-open-test.sh" > "$TEST_REPO/activate.sh"
  status=0
  bash "$TEST_REPO/activate.sh" > "$TEST_REPO/output.log" 2>&1 || status=$?
  case "$scenario" in
    cold|warm|resume|changed|profile_changed)
      [[ "$status" = 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      [[ ! -e "$TEST_DEPLOY_ROOT/world-activation.pending" ]]
      grep -q 'READY ' "$TEST_REPO/output.log"
      if [[ "$scenario" = warm || "$scenario" = profile_changed ]]; then
        cmp "$TEST_REPO/.env" "$TEST_REPO/env-before"
        ! grep -Eq 'release/test-install|compose stop|world-worker/run|docker stop' "$TEST_LOG"
      else
        grep -q 'release/test-install' "$TEST_LOG"
        grep -Fxq 'compose up -d --no-deps php nginx' "$TEST_LOG"
      fi
      if [[ "$scenario" = profile_changed ]]; then grep -q 'release/test-profile 1 fedornabilkin' "$TEST_LOG"; else grep -q 'release/test-profile 0 fedornabilkin' "$TEST_LOG"; fi
      ;;
    *)
      [[ "$status" != 0 ]] || { cat "$TEST_REPO/output.log"; exit 1; }
      ! grep -q 'READY ' "$TEST_REPO/output.log"
      if [[ "$scenario" = label || "$scenario" = lock ]]; then ! grep -q '^docker stop' "$TEST_LOG"; fi
      if [[ "$scenario" = setup || "$scenario" = handlers || "$scenario" = worker || "$scenario" = profile || "$scenario" = state ]]; then [[ -e "$TEST_DEPLOY_ROOT/world-activation.pending" ]]; fi
      ;;
  esac
  grep -Fxq 'DB_FIXTURE=preserved' "$TEST_REPO/.env"
  ! grep -Eq 'pg_dump|pg_restore|createdb|dropdb|world-verify-test' "$TEST_LOG"
  printf 'PASS test release activation: %s\n' "$scenario"
done

# Real POSIX signals: finish the running batch, then exit without starting another.
# Windows Git Bash does not implement POSIX subprocess signals; CI runs Linux.
if [[ "${OSTYPE:-}" != msys* && "${OSTYPE:-}" != cygwin* ]]; then
  python3 - "$source_root/yii2/console/world-worker-loop.sh" "$test_root" <<'PY'
import os, pathlib, signal, subprocess, sys, time
root = pathlib.Path(sys.argv[2]) / 'signals'
root.mkdir()
php = root / 'php'
php.write_text('#!/bin/sh\necho start >> "$BATCH_LOG"\nsleep 0.3\necho finish >> "$BATCH_LOG"\n')
php.chmod(0o755)
for sig in (signal.SIGTERM, signal.SIGQUIT):
    log = root / str(sig)
    env = dict(os.environ, PATH=str(root) + ':' + os.environ['PATH'], BATCH_LOG=str(log))
    process = subprocess.Popen(['/bin/sh', sys.argv[1]], env=env)
    try:
        deadline = time.monotonic() + 3
        while not log.exists() and time.monotonic() < deadline: time.sleep(0.01)
        assert log.exists(), 'worker did not start'
        process.send_signal(sig)
        assert process.wait(timeout=3) == 0
        assert log.read_text().splitlines() == ['start', 'finish'], 'batch interrupted or repeated'
    finally:
        if process.poll() is None: process.kill(); process.wait()
print('PASS worker TERM/QUIT finish current batch')
PY
else
  printf 'SKIP POSIX signal checks on Windows; required in Linux CI\n'
fi

export PATH="$test_root/bin:$PATH"
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
