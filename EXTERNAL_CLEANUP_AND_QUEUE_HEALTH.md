# Этап 10: очистка внешних ресурсов и health-check очереди

## Установка

Этап устанавливается поверх этапа 9.

1. Сделать резервную копию PostgreSQL.
2. Развернуть файлы этапа 10.
3. Применить миграцию:

```text
/local/sitebuilder/tools/apply_stage10_migration.php
```

4. Очистить OPcache.
5. Убедиться, что worker запускается cron не реже одного раза в минуту.

Пример:

```cron
* * * * * DOCUMENT_ROOT=/home/bitrix/www /usr/bin/php /home/bitrix/www/local/sitebuilder/tools/queue_worker_cli.php --limit=50 --worker=sitebuilder-main >> /var/log/sitebuilder-queue.log 2>&1
```

## Удаление сайта

Удаление сайта по-прежнему атомарно удаляет данные PostgreSQL. До удаления строки сайта в той же транзакции:

1. отменяются старые задания сайта со статусом `pending` или `retry`;
2. сохраняются точные ID рабочей группы и папки Диска;
3. создаются cleanup-задания:
   - `bitrix.group.delete`;
   - `disk.site_folder.delete`.

Если постановка cleanup-задания не удалась, удаление сайта откатывается.

### Безопасность удаления группы

Группа удаляется только когда:

- её ID был сохранён в строке сайта;
- у сайта заполнен `bitrix_group_created_by`, то есть группа помечена как созданная SiteBuilder;
- найденная группа имеет имя с префиксом `SiteBuilder:`.

Отсутствующая группа считается уже успешно удалённой.

### Безопасность удаления папки Диска

Папка удаляется только по сохранённому ID. Worker дополнительно проверяет, что объект является прямым потомком:

```text
Общий диск / SiteBuilder
```

Корневая папка `SiteBuilder`, папка из другого раздела Диска или произвольный ID не удаляются. Отсутствующая папка считается уже удалённой.

Обе операции идемпотентны и могут безопасно повторяться.

## Heartbeat и метрики

Миграция создаёт:

```text
sitebuilder.queue_worker_state
sitebuilder.queue_worker_run
```

`queue_worker_state` хранит последний heartbeat, текущий job и накопительные счётчики worker. `queue_worker_run` хранит отдельный результат каждого batch-запуска.

Страница диагностики:

```text
/local/sitebuilder/queue_health.php
```

Для конкретного сайта:

```text
/local/sitebuilder/queue_health.php?siteId=13
```

CLI health-check:

```bash
DOCUMENT_ROOT=/home/bitrix/www php /home/bitrix/www/local/sitebuilder/tools/queue_health_cli.php
```

Коды завершения:

- `0` — `healthy`;
- `1` — `warning`;
- `2` — `critical` или ошибка проверки.

Проверка конкретного сайта:

```bash
php tools/queue_health_cli.php --site=13
```

## Правила health-check

Статус становится `critical`, если:

- есть готовые задания, но heartbeat worker отсутствует или сильно устарел;
- есть зависшие `running`-задания;
- cleanup-задание окончательно завершилось со статусом `dead`;
- старейшее готовое задание превышает критический порог.

Статус `warning` используется для задержанного heartbeat, старой очереди и обычных `dead`-заданий.

Пороги находятся в:

```text
/local/sitebuilder/config/queue.php
```

## Retention

Автоматическое обслуживание удаляет:

- историю запусков worker старше `queue_worker_run_retention_days`;
- неактивные heartbeat-записи старше `queue_worker_state_retention_days`.

Настройки находятся в `config/maintenance.php`.

## Проверка после установки

1. Запустить worker вручную и открыть `queue_health.php`.
2. Убедиться, что heartbeat свежий и появился запуск за последние 24 часа.
3. Создать тестовый сайт и дождаться создания группы и папки.
4. Удалить сайт.
5. Проверить наличие двух cleanup-заданий.
6. Запустить worker.
7. Проверить, что группа и папка удалены, а задания имеют статус `succeeded`.
8. Перед production отдельно проверить, что папка Диска удаляется в корзину штатным механизмом конкретной версии Битрикс24.
