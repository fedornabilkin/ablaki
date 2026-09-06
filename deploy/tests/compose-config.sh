#!/usr/bin/env bash
# Linux CI only; parses disposable examples without a Docker daemon or real .env.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
command -v docker >/dev/null
command -v python3 >/dev/null
test_root=$(mktemp -d "${TMPDIR:-/tmp}/ablaki-compose-config.XXXXXXXX")
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/home"

for environment in production test legacy; do
  fixture="$test_root/$environment"
  mkdir -p "$fixture"
  cp "$source_root/docker-compose.yaml" "$fixture/docker-compose.yaml"
  example=$environment
  if [[ "$environment" = legacy ]]; then example=test; fi
  cp "$source_root/deploy/env/$example.env.example" "$fixture/.env"
  # Examples document merge-only settings. Concrete values below belong only to CI.
  if [[ "$environment" = production ]]; then
    cat >> "$fixture/.env" <<'ENV'

COMPOSE_PROJECT_NAME=ablaki-production
COMPOSE_SUBNET=192.168.23.0/24
APP_BIND_IP=127.0.0.1
PORT_NGINX_API=19881
PORT_NGINX_FRONT=18091
PORT_NGINX_ADMIN=18081
MYSQL_DB_HOST=existing-mysql.invalid
MYSQL_DB_NAME=existing_production
MYSQL_DB_USER=fixture_user
MYSQL_DB_PASSWORD=fixture_password
ENV
  else
    cat >> "$fixture/.env" <<'ENV'

COMPOSE_PROJECT_NAME=ablaki
COMPOSE_SUBNET=192.168.22.0/24
APP_BIND_IP=0.0.0.0
PG_BIND_IP=127.0.0.1
PORT_NGINX_API=3180
PORT_NGINX_FRONT=18092
PORT_NGINX_ADMIN=3195
PG_DB_PORT=15432
PG_DB_HOST=postgres
PG_DB_NAME=existing_test
PG_DB_USER=fixture_user
PG_DB_PASSWORD=fixture_password
ENV
  fi
  if [[ "$environment" = legacy ]]; then
    sed -i '/^APP_BIND_IP=/d; /^COMPOSE_SUBNET=/d' "$fixture/.env"
  fi
  # Clear inherited COMPOSE_*, port and DB overrides, and do not read host config.
  env -i PATH="$PATH" HOME="$test_root/home" docker compose \
    --project-directory "$fixture" --env-file "$fixture/.env" \
    -f "$fixture/docker-compose.yaml" config --format json > "$fixture/config.json"
done

python3 - "$test_root" <<'PY'
import ipaddress
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
configs = {name: json.loads((root / name / 'config.json').read_text())
           for name in ('production', 'test', 'legacy')}
all_ports = set()
for name, project, subnet, api_port in (
        ('production', 'ablaki-production', '192.168.23.0/24', 19881),
        ('test', 'ablaki', '192.168.22.0/24', 3180)):
    config = configs[name]
    assert config['name'] == project, (name, 'unexpected Compose project')
    assert config['networks']['default']['name'] == project + '_default'
    assert config['networks']['default']['ipam']['config'][0]['subnet'] == subnet
    assert not config['networks']['default'].get('external', False)
    assert set(config['networks']) == {'default'}
    for key, volume in config['volumes'].items():
        assert volume['name'] == project + '_' + key
        assert not volume.get('external', False)
    services = config['services']
    assert services['php']['environment']['APP_ENVIRONMENT'] == name
    if name == 'production':
        assert services['php']['environment']['MYSQL_DB_HOST'] == 'existing-mysql.invalid'
        assert services['php']['environment']['MYSQL_DB_NAME'] == 'existing_production'
        assert not services['postgres']['environment']['POSTGRES_DB']
    else:
        assert services['php']['environment']['PG_DB_HOST'] == 'postgres'
        assert services['postgres']['environment']['POSTGRES_DB'] == 'existing_test'
        assert services['postgres']['environment']['POSTGRES_USER'] == 'fixture_user'
        assert services['postgres']['environment']['POSTGRES_PASSWORD'] == 'fixture_password'
        assert services['php']['environment']['PG_DB_NAME'] == services['postgres']['environment']['POSTGRES_DB']
    for service_name, service in services.items():
        assert not service.get('container_name'), 'Fixed container names collide across projects'
        assert set(service['networks']) == {'default'}
        for port in service.get('ports', []):
            expected_bind = '0.0.0.0' if name == 'test' and service_name == 'nginx' else '127.0.0.1'
            assert port['host_ip'] == expected_bind, (name, service_name, 'unexpected port binding')
            published = int(port['published'])
            assert published not in all_ports, (name, 'duplicate host port')
            all_ports.add(published)
    api = next(port for port in services['nginx']['ports'] if int(port['target']) == 80)
    assert int(api['published']) == api_port
    db_volume = next(volume for volume in services['postgres']['volumes'] if volume['target'] == '/data/postgres')
    assert db_volume['type'] == 'volume' and db_volume['source'] == 'postgresdata'
    print('PASS isolated Compose configuration:', name)

assert not ipaddress.ip_network('192.168.22.0/24').overlaps(ipaddress.ip_network('192.168.23.0/24'))
assert len(all_ports) == 8
legacy = configs['legacy']
assert legacy['networks']['default']['ipam']['config'][0]['subnet'] == '192.168.22.0/24'
for service in legacy['services'].values():
    assert all(port['host_ip'] == '127.0.0.1' for port in service.get('ports', []))
assert legacy['volumes']['postgresdata']['name'] == 'ablaki_postgresdata'
print('PASS missing new variables preserve the legacy subnet and local port binding')
PY
