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
        ('test', 'ablaki', '192.168.22.0/24', 19882)):
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
    assert services['php']['environment']['PG_DB_HOST'] == 'postgres'
    assert services['postgres']['environment']['POSTGRES_DB'] == 'ablaki_' + name
    assert services['postgres']['environment']['POSTGRES_USER'] == 'ablaki_' + name
    assert services['postgres']['environment']['POSTGRES_PASSWORD'] == 'REPLACE_ME'
    assert services['php']['environment']['PG_DB_NAME'] == services['postgres']['environment']['POSTGRES_DB']
    for service in services.values():
        assert not service.get('container_name'), 'Fixed container names collide across projects'
        assert set(service['networks']) == {'default'}
        for port in service.get('ports', []):
            assert port['host_ip'] == '127.0.0.1', (name, 'public port binding')
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
