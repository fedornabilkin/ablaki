#!/bin/sh

cd /web/frontend/ && npm i && npm run build

tail -f /dev/null