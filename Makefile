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
	docker-compose pull

# build
docker-build:
	docker-compose build
build-nginx:
	docker-compose build nginx
build-php:
	docker-compose build php
build-php-with-xdebug:
	docker-compose build --build-arg ENV=DEV php

# up/down
docker-up:
	docker-compose up --detach --remove-orphans
	docker-compose ps
up-php:
	docker-compose up --detach php
up-nginx:
	docker-compose up --detach nginx
docker-down:
	docker-compose down --remove-orphans
docker-down-clear:
	docker-compose down --volumes --remove-orphans

# start/restart/stop
docker-stop:
	docker-compose stop
docker-start:
	docker-compose start
	docker-compose ps
docker-restart:
	docker-compose restart
	docker-compose ps
restart-php:
	docker-compose restart php
restart-nginx:
	docker-compose restart nginx

# migrations
migration: # migration up (all new migrations) in php container
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate --interactive=0
migration-down: # down one last migration
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate/down $(M_CNT) --interactive=0
migration-redo: # revert one last migration (down and up)
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate/redo $(M_CNT) --interactive=0

# tests
codecept: # start tests
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php vendor/bin/codecept run

# shell
shell-php:
	docker-compose exec php bash
shell-nginx:
	docker-compose exec nginx sh
shell-redis:
	docker-compose exec redis sh
shell-composer:
	docker run -v "$(PWD)/yii2:/web/yii2" -w /web/yii2 -i -t composer bash

# logs
log-nginx:
	docker-compose logs --follow nginx
log-php:
	docker-compose logs --follow php
log-composer:
	docker-compose logs --follow composer
logs:
	docker-compose logs --follow

# build images
build-image-php-fpm:
	docker build -t $(REGISTRY_PHP_FPM) -f docker/php/Dockerfile ./docker/php
	docker push $(REGISTRY_PHP_FPM)
