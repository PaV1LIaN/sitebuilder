# SiteBuilder: сверка внешних ресурсов и системные оповещения

Этап 11 добавляет контроль согласованности PostgreSQL с рабочими группами Битрикс24 и папками Битрикс.Диска.

## Что проверяется

Для каждого сайта сверяются:

- `sitebuilder.site.bitrix_group_id` и фактическая рабочая группа;
- `sitebuilder.site.disk_folder_id` и фактическая папка под служебным корнем `Общий диск / SiteBuilder`;
- служебное имя группы `SiteBuilder: <название сайта>`;
- имя папки, сформированное из slug сайта;
- принадлежность группы и папки управляемым объектам SiteBuilder.

Глобальная сверка дополнительно перечисляет управляемые группы и папки и находит ресурсы, которые больше не связаны ни с одним сайтом.

## Режимы

### audit

Только проверяет состояние, обновляет реестр и создаёт оповещения. Внешние ресурсы и строки сайта не изменяются.

### repair

Дополнительно:

- отвязывает отсутствующий внешний объект от сайта;
- ставит в outbox идемпотентное задание создания новой группы или папки;
- не удаляет сироты автоматически;
- не заменяет ссылку на чужую группу или папку вне каталога SiteBuilder.

## Безопасность удаления сирот

Удаление сироты доступно только администратору Битрикс и выполняется через outbox.

Перед постановкой задания и повторно непосредственно перед внешним удалением проверяется, что ID не появился в `sitebuilder.site`. Если ресурс успели привязать после сверки, worker завершает задание без удаления с причиной `resource_attached_after_reconcile`.

Группа удаляется только при наличии префикса `SiteBuilder:`. Папка удаляется только если является прямым дочерним объектом служебного корня SiteBuilder.

## Конкурентные запуски

- глобальный запуск получает exclusive advisory lock;
- запуск для одного сайта получает shared global lock и отдельный exclusive lock сайта;
- разные сайты могут проверяться параллельно;
- глобальный и точечный запуски не пересекаются;
- занятый запуск возвращает `RECONCILE_BUSY`, поэтому outbox повторяет его позже.

## Автоматический запуск

Worker вызывает `ExternalResourceReconcileService::enqueueIfDue()`. Период задаётся в:

```text
/local/sitebuilder/config/reconciliation.php
```

По умолчанию глобальный audit ставится в очередь не чаще одного раза в 6 часов.

Рекомендуемый worker из этапов 9–10 продолжает использоваться без отдельного cron:

```cron
* * * * * DOCUMENT_ROOT=/home/bitrix/www /usr/bin/php /home/bitrix/www/local/sitebuilder/tools/queue_worker_cli.php --limit=50 --worker=sitebuilder-main >> /var/log/sitebuilder-queue.log 2>&1
```

Для отдельной диагностики доступен CLI:

```bash
DOCUMENT_ROOT=/home/bitrix/www \
php /home/bitrix/www/local/sitebuilder/tools/external_reconcile_cli.php --mode=audit
```

Для одного сайта:

```bash
DOCUMENT_ROOT=/home/bitrix/www \
php /home/bitrix/www/local/sitebuilder/tools/external_reconcile_cli.php --site=13 --mode=repair
```

Код завершения: `0` — аномалий нет, `1` — аномалии найдены, `2` — ошибка запуска.

## Системные оповещения

Оповещения хранятся в `sitebuilder.system_alert` и имеют состояния:

- `open`;
- `acknowledged`;
- `resolved`.

Повторное обнаружение увеличивает счётчик и обновляет `last_seen_at`. Подтверждённое оповещение не открывается повторно, пока проблема не была сначала закрыта. После фактического устранения соответствующее оповещение переводится в `resolved`.

Внутренний интерфейс:

```text
/local/sitebuilder/alerts.php
/local/sitebuilder/external_resources.php
```

`OWNER` и `ADMIN` видят данные своего сайта. Глобальный просмотр и удаление сирот доступны только администратору Битрикс.

## Внешние уведомления

Доставка в IM Битрикс24 и email выключена по умолчанию. Настройки:

```text
/local/sitebuilder/config/notifications.php
```

Пример:

```php
<?php
return [
    'external_delivery_enabled' => true,
    'minimum_severity' => 'critical',
    'cooldown_seconds' => 3600,
    'bitrix_user_ids' => [1, 42],
    'email_addresses' => ['sitebuilder-admin@example.org'],
    'subject_prefix' => '[SiteBuilder]',
];
```

Пароли, токены, содержимое файлов и полный пользовательский контент в уведомления не передаются.

## Миграция

Этап 11 устанавливается поверх этапа 10. Перед установкой нужно остановить worker, заменить файлы и применить:

```text
/local/sitebuilder/tools/apply_stage11_migration.php
```

После успешной миграции worker можно запустить снова. Код этапа умеет пережить короткое окно rolling deployment: до появления таблиц этапа 11 автоматическая постановка сверки не выполняется, а успешные старые задания не откатываются из-за отсутствия служебного реестра.

## Новые таблицы

- `sitebuilder.external_reconcile_run` — история запусков;
- `sitebuilder.external_resource_registry` — фактическое состояние внешних объектов;
- `sitebuilder.system_alert` — активные и закрытые оповещения;
- `sitebuilder.system_alert_delivery` — история попыток внешней доставки.

Старые закрытые данные удаляются существующим `MaintenanceService` по срокам из `config/maintenance.php`.
