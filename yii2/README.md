https://github.com/shieldfy/API-Security-Checklist/blob/master/README-ru.md

#### Выкатываем миграции по очереди
```
php yii migrate/up --migrationPath=@vendor/dektrium/yii2-user/migrations
php yii migrate --migrationPath=@fedornabilkin/binds/migrations
php yii migrate --migrationPath=@fedornabilkin/redirect/migrations
php yii migrate --migrationPath=@yii/rbac/migrations
```
