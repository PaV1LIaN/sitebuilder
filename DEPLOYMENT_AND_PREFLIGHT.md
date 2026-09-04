# Этап 13: единые миграции и preflight

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

После первой установки публичных адресов откройте `deployment.php` и нажмите
`Установить публичные URL`. Действие регистрирует в штатном `urlrewrite.php`
Битрикса два правила с собственными ID `sitebuilder:public` и
`sitebuilder:sitemap`; остальные правила портала не изменяются.

Повторно нажмите эту кнопку после обновления со старой реализации ЧПУ. Установка
удалит только прежнее правило SiteBuilder, направленное в `router.php`, и запишет
новые правила с приоритетом выше общей карты сайта портала. Экран развёртывания
проверяет не только наличие правил, но и какой маршрут фактически срабатывает
первым.

Канонические адреса:

```text
/local/sitebuilder/s/{site-slug}/
/local/sitebuilder/s/{site-slug}/{parent-slug}/{page-slug}/
/local/sitebuilder/s/{site-slug}/sitemap.xml
```

Старые ссылки `public.php?siteId=...&pageId=...` отвечают постоянным редиректом
на канонический адрес без ID.

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
