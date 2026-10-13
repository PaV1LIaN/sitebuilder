# Этап 13: единые миграции и preflight

## Важно: персональные настройки SiteBuilder в этой сборке

Этот экземпляр проекта предназначен для конкретного портала. По запросу
владельца **уже прописаны в `config/auth.php`**:

- `admin_user_ids = [40, 41, 96, 97, 98, 99]`;
- `guest_user_id = 665`.

Текущий GitHub-репозиторий публичен, поэтому эти технические ID видны в
коде. Они не дают прав администратора портала Битрикс: список даёт только
роль `ADMIN` SiteBuilder. Технический гость всегда исключается.

Для повторных обновлений предусмотрен `config/auth.local.php`, который
загружается **поверх** `config/auth.php` и исключён из Git. Если он есть
на сервере, его значения, в том числе `admin_user_ids`, имеют приоритет.

**Готовый архив из GitHub Actions (`sitebuilder-ready-to-upload`) уже
содержит оба файла** с перечисленными ID. Поэтому его можно загрузить
поверх существующего `/local/sitebuilder/` без ручного заполнения ID.
Вместе с тем заранее сохраните прежние локальные настройки, если на
сервере есть другие индивидуальные параметры.

См. `READY_TO_UPLOAD.md` и `docs/DISK_ADMIN_ACCESS_FIX.md`.

- Не удаляйте существующий каталог `/local/sitebuilder/` перед обновлением.
- Сохраните резервные копии `config/auth.php`, `config/auth.local.php`
  и PostgreSQL.
- Не очищайте таблицы прав SiteBuilder или ACL Диска Битрикс.
- На нескольких веб-узлах обновите каждый узел, если приложение не на
  общем хранилище.
- Миграции в данном обновлении не меняются.


## Что изменилось

Начиная с этапа 13 миграции регистрируются в PostgreSQL:

- `sitebuilder.schema_migration` — применённые SQL-файлы и их SHA-256;
- `sitebuilder.deployment_run` — история bootstrap, migrate и preflight.

Изменять уже применённый SQL-файл нельзя. Если его checksum изменился, развёртывание останавливается с `MIGRATION_CHECKSUM_DRIFT`. Исправление оформляется новым SQL-файлом.

## Первичная установка этапа 13

1. Сделать резервную копию PostgreSQL.
2. Остановить worker и изменяющие операции SiteBuilder.
3. Развернуть файлы этапа 13.
4. Открыть:

```text
/local/sitebuilder/tools/apply_stage13_migration.php
```

Страница создаст реестр, определит уже существующие этапы по структуре базы, выполнит только отсутствующие миграции и перенесёт резервные копии из `/upload/sitebuilder/backups` в приватный каталог вне `DOCUMENT_ROOT`.

## Последующие обновления

Веб-интерфейс:

```text
/local/sitebuilder/deployment.php
```

CLI:

```bash
DOCUMENT_ROOT=/home/bitrix/www \
php /home/bitrix/www/local/sitebuilder/tools/migrate_cli.php --status

DOCUMENT_ROOT=/home/bitrix/www \
php /home/bitrix/www/local/sitebuilder/tools/migrate_cli.php --apply
```

Коды завершения CLI:

- `0` — схема актуальна;
- `1` — остались pending/drift;
- `2` — миграция завершилась ошибкой.

## Preflight

Веб-интерфейс:

```text
/local/sitebuilder/preflight.php
```

CLI:

```bash
DOCUMENT_ROOT=/home/bitrix/www \
php /home/bitrix/www/local/sitebuilder/tools/preflight_cli.php
```

Проверяются:

- версия PHP и обязательные расширения;
- `display_errors` и лимиты загрузки;
- подключение и версия PostgreSQL;
- базовые таблицы и состояние миграций;
- writable-каталоги и свободное место;
- модули `disk` и `socialnetwork`;
- технический гостевой пользователь;
- heartbeat worker, pending/dead jobs;
- открытые критические оповещения.

Коды завершения preflight CLI:

- `0` — готово к работе;
- `1` — только предупреждения;
- `2` — есть блокирующие ошибки или проверка не выполнена.

## Правило для новых миграций

1. Создать новый файл в `migrations/`.
2. Добавить его в `MigrationService::manifest()`.
3. Никогда не редактировать файл, уже зарегистрированный в production.
4. Проверить `migrate_cli.php --status` и `preflight_cli.php` до запуска worker.

## Приватное хранилище резервных копий

Новые копии сохраняются вне web-корня:

```text
dirname(DOCUMENT_ROOT)/sitebuilder-private/backups
```

Путь можно переопределить через `absolute_directory` в `config/backup.php`. Старый каталог внутри `/upload` используется только как источник одноразового переноса. Preflight блокирует production-ready статус, пока в старом каталоге остаются файлы копий.
