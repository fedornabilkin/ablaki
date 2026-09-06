COMPOSE ?= bash deploy/compose.sh

ifeq ($(M_CNT),)
    M_CNT=1
endif

#REGISTRY=127.0.0.1:30500
#PHP_FPM_IMAGE=blk_php

ifeq ($(D_TAG),)
  D_TAG=develop
endif

#REGISTRY_PHP_FPM=$(REGISTRY)/$(PHP_FPM_IMAGE):$(D_TAG)

up: docker-up
down: docker-down
stop: docker-stop
start: docker-start
restart: docker-restart
build: docker-build
pull: docker-pull
init: docker-down-clear docker-pull docker-build docker-up

docker-pull:
	$(COMPOSE) pull

# build
docker-build:
	$(COMPOSE) build
build-nginx:
	$(COMPOSE) build nginx
build-php:
	$(COMPOSE) build php
build-php-with-xdebug:
	$(COMPOSE) build --build-arg ENV=DEV php

# up/down
docker-up:
	bash deploy/start.sh
up-php:
	$(COMPOSE) up --detach php
up-nginx:
	$(COMPOSE) up --detach nginx
docker-down:
	$(COMPOSE) down --remove-orphans
docker-down-clear:
	$(COMPOSE) down --volumes --remove-orphans

# start/restart/stop
docker-stop:
	$(COMPOSE) stop
docker-start:
	$(COMPOSE) start
	$(COMPOSE) ps
docker-restart:
	$(COMPOSE) restart
	$(COMPOSE) ps
restart-php:
	$(COMPOSE) restart php
restart-nginx:
	$(COMPOSE) restart nginx

# migrations
migration: # fail fast; no FPM or cron in the migration container
	bash deploy/migrate.sh
migration-down: # down one last migration
	$(COMPOSE) run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate/down $(M_CNT) --interactive=0
migration-redo: # revert one last migration (down and up)
	$(COMPOSE) run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate/redo $(M_CNT) --interactive=0

# cache
cache-flush-all:
	$(COMPOSE) run --rm -v "$(PWD)/yii2:/web/yii2" php php yii cache/flush-all
cache-flush-schema:
	$(COMPOSE) run --rm -v "$(PWD)/yii2:/web/yii2" php php yii cache/flush-schema

# tests
codecept: # start tests
	$(COMPOSE) run --rm -v "$(PWD)/yii2:/web/yii2" php vendor/bin/codecept run

# shell
shell-php:
	$(COMPOSE) exec php bash
shell-nginx:
	$(COMPOSE) exec nginx sh
shell-redis:
	$(COMPOSE) exec redis sh
shell-composer:
	docker run -v "$(PWD)/yii2:/web/yii2" -w /web/yii2 -i -t composer bash

# logs
log-nginx:
	$(COMPOSE) logs --follow nginx
log-php:
	$(COMPOSE) logs --follow php
log-composer:
	$(COMPOSE) logs --follow composer
logs:
	$(COMPOSE) logs --follow

# build images
build-image-php-fpm:
	docker build -t $(REGISTRY_PHP_FPM) -f docker/php/Dockerfile ./docker/php
	docker push $(REGISTRY_PHP_FPM)
