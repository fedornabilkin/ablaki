ifeq ($(M_CNT),)
    M_CNT=1
endif

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

docker-down:
	docker-compose down --remove-orphans

# init
docker-down-clear:
	docker-compose down --volumes --remove-orphans

docker-pull:
	docker-compose pull

docker-build:
	docker-compose build

docker-up:
	docker-compose up --detach --remove-orphans
	docker-compose ps
#init

up-php:
	docker-compose up --detach php

up-nginx:
	docker-compose up --detach nginx

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

composer:
	docker-compose up --detach composer

migration: # migration up (all new migrations) in php container
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate --interactive=0

migration-down: # down one last migration
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate/down $(M_CNT) --interactive=0

migration-redo: # revert one last migration (down and up)
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php php yii migrate/redo $(M_CNT) --interactive=0

codecept: # start tests
	docker-compose run --rm -v "$(PWD)/yii2:/web/yii2" php vendor/bin/codecept run

shell-php:
	docker-compose exec php bash

shell-nginx:
	docker-compose exec nginx sh

shell-redis:
	docker-compose exec redis sh

shell-composer:
	docker run -v "$(PWD)/yii2:/web/yii2" -w /web/yii2 -i -t composer bash

log-nginx:
	docker-compose logs --follow nginx

log-php:
	docker-compose logs --follow php

log-composer:
	docker-compose logs --follow composer

logs:
	docker-compose logs --follow
