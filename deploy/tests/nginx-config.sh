#!/usr/bin/env bash
# Syntax only: disposable certificates/containers, no listening host ports.
set -Eeuo pipefail
source_root=$(cd "$(dirname "$0")/../.." && pwd)
test_root=$(mktemp -d "${TMPDIR:-/tmp}/ablaki-nginx-config.XXXXXXXX")
cleanup() {
  case "$test_root" in "${TMPDIR:-/tmp}/ablaki-nginx-config."*) rm -rf -- "$test_root" ;; esac
}
trap cleanup EXIT
mkdir -p "$test_root/certs" "$test_root/conf"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=ci.invalid' \
  -keyout "$test_root/key.pem" -out "$test_root/cert.pem" > /dev/null 2>&1
for domain in api.ablakin.ru api-test.ablakin.ru; do
  mkdir -p "$test_root/certs/$domain"
  cp "$test_root/key.pem" "$test_root/certs/$domain/privkey.pem"
  cp "$test_root/cert.pem" "$test_root/certs/$domain/fullchain.pem"
done
for template in "$source_root"/deploy/nginx/*.conf.example; do
  cp "$template" "$test_root/conf/default.conf"
  docker run --rm -v "$test_root/conf:/etc/nginx/conf.d:ro" \
    -v "$test_root/certs:/etc/letsencrypt/live:ro" nginx:1.15 nginx -t
  printf 'PASS nginx syntax: %s\n' "$(basename "$template")"
done
docker run --rm --add-host php:127.0.0.1 \
  -v "$source_root/docker/nginx/conf.d:/etc/nginx/conf.d:ro" \
  -v "$source_root/docker/nginx/add:/etc/nginx/add:ro" nginx:1.15 nginx -t
