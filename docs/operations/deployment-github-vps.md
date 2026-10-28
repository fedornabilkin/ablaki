# Деплой backend

[Актуальная инструкция и восстановление серверных настроек](production-test-deployment.md).

Docker, Makefile и запуск Yii восстановлены из c875c7c — версии до автоматизации.
Серверный скрипт выполняет git pull и make up в /var/www/api.ablakin.ru для production
или /var/code/ablaki для test. make up имеет прежнее содержимое: docker-compose up
--detach --remove-orphans, затем docker-compose ps; миграции остаются в старом entrypoint.

Прикладные изменения сохраняются. Git-откат не меняет .env на VPS, уже установленные
зависимости, Docker-ресурсы и данные. Прежнее имя Compose project нужно сверить
по существующим контейнерам, а не назначать заново.
