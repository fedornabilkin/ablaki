# Работа с backend

Перед изменениями прочитайте agreements/general.md и agreements/architecture.md.
Планы оформляйте по agreements/plans.md; релиз — по agreements/deployment.md.
PHP-приложения находятся в yii2. Проверяйте изменённый PHP через lint и тесты затронутого поведения.
Не используйте production-БД для тестов. SQLite-проверки не заменяют проверку блокировок целевого PostgreSQL/MySQL.
Текущие блокеры и результаты проверки веток находятся в plans/2026-09-05-critical-refactoring.md и plans/2026-09-05-branch-audit.md.
