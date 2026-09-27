#!/usr/bin/env bash
# Install and open the world browser/onboarding on the test checkout only.
# Canonical inventory and wallet conversion have separate offline rollouts.
set -euo pipefail
cd /var/code/ablaki
[[ "$(pwd -P)" = /var/code/ablaki ]]
[[ "$(git branch --show-current)" = test ]]
[[ -f .env && ! -L .env && -w .env ]]
exec 9>/opt/ablaki-backend-test/world-setup.lock
flock -n 9

world_setup() {
  docker-compose exec -T -e WORLD_INSTALL=confirmed-world-install php php /web/yii2/yii "world-setup/$@"
}
world_setup install
world_setup flag world_read 1
world_setup flag world_write 1

# Change only the two world UI flags; preserve all other local configuration.
# The same mounted .env is read by PHP-FPM, and env_file supplies console calls.
if ! grep -qx 'WORLD_READ=1' .env || ! grep -qx 'WORLD_WRITE=1' .env; then
  sed -i '/^WORLD_READ=/d; /^WORLD_WRITE=/d' .env
  printf '\nWORLD_READ=1\nWORLD_WRITE=1\n' >> .env
  docker-compose up -d --no-deps php
fi
printf 'World schema, navigation and onboarding are enabled on test.\n'
printf 'Storage and wallet activation remain explicit offline procedures.\n'
