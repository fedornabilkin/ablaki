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

build-nginx:
	docker-compose build nginx

build-php:
	docker-compose build php

build-php-with-xdebug:
	docker-compose build --build-arg ENV=DEV php

docker-up:
	docker-compose up --detach --remove-orphans
	docker-compose ps

docker-down:
	docker-compose down --remove-orphans

docker-down-clear:
	docker-compose down --volumes --remove-orphans

docker-stop:
	docker-compose stop

docker-start:
	docker-compose start

docker-restart:
	docker-compose restart

docker-pull:
	docker-compose pull

docker-build:
	docker-compose build

composer:
	docker-compose up --detach composer

up-php:
	docker-compose up --detach php

up-nginx:
	docker-compose up --detach nginx

shell-php:
	docker-compose exec php bash

shell-nginx:
	docker-compose exec nginx bash

shell-redis:
	docker-compose exec redis sh

log-nginx:
	docker-compose logs --follow nginx

log-php:
	docker-compose logs --follow php

log-composer:
	docker-compose logs --follow composer

logs:
	docker-compose logs --follow

build-image-php-fpm:
	docker build -t $(REGISTRY_PHP_FPM) -f docker/php/Dockerfile ./docker/php
	docker push $(REGISTRY_PHP_FPM)
