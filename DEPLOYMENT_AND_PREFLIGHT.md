# Этап 13: единые миграции и preflight

## Важно: сохраняйте локальную авторизацию при обновлении

Параметры конкретного портала (ID администраторов SiteBuilder, технический
guest_user_id) **не следует записывать в публичный GitHub-репозиторий**.

- Создайте **на рабочем сервере** `/local/sitebuilder/config/auth.local.php`,
  взяв за основу `config/auth.local.example.php`.
- Укажите `admin_user_ids` и при необходимости `guest_user_id` в локальном файле.
- `lib/auth.php` объединяет стандартный `config/auth.php` и локальные
  переопределения. `auth.local.php` не находится в Git и не включается в архив.
- Обновляйте файлы SiteBuilder **поверх существующих**, без удаления каталога.
  Не удаляйте `auth.local.php` и не перезаписывайте его при обновлении.
- Для прежней версии, где администраторы были вручную добавлены в
  `config/auth.php`, перед обновлением скопируйте этот список в
  `config/auth.local.php`; иначе дефолтный `auth.php` из Git заменит его.
- Права в PostgreSQL и права папок Битрикс **не пересоздавайте** только из-за
  замены кода. При недоступности Диска сначала проверяйте ответ
  `components/disk/api.php?action=bootstrap` и ошибки PHP.
- Выполняйте резервное копирование конфигурации и базы перед обновлением.
  При нескольких веб-узлах проверяйте локальные настройки на **каждом** из них.

Пользователи из `admin_user_ids` получают роль `ADMIN` SiteBuilder
на всех сайтах, но **не** права администратора портала Битрикс24.
Гостевой пользователь исключён. Администраторы Битрикс24 сохраняют
свой отдельный полный доступ.


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
