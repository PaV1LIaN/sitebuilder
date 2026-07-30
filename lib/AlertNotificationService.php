<?php

use Bitrix\Main\Loader;

require_once __DIR__ . '/db.php';

/** Необязательная доставка системных оповещений в IM Битрикс24 и email. */
final class AlertNotificationService
{
    public static function notifyIfDue(array $alert): array
    {
        $config = self::config();
        if (empty($config['external_delivery_enabled'])) {
            return ['enabled' => false, 'delivered' => 0, 'failed' => 0];
        }
        if (!self::severityAllowed((string)($alert['severity'] ?? 'info'), (string)$config['minimum_severity'])) {
            return ['enabled' => true, 'delivered' => 0, 'failed' => 0, 'skipped' => 'severity'];
        }

        $bitrixUsers = array_values(array_unique(array_filter(array_map('intval', (array)$config['bitrix_user_ids']))));
        $emails = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)$config['email_addresses']
        ), static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)));
        if (!$bitrixUsers && !$emails) {
            return ['enabled' => true, 'delivered' => 0, 'failed' => 0, 'skipped' => 'no_recipients'];
        }

        $claimed = self::claimWindow((int)$alert['id'], max(60, (int)$config['cooldown_seconds']));
        if (!$claimed) {
            return ['enabled' => true, 'delivered' => 0, 'failed' => 0, 'skipped' => 'cooldown'];
        }

        $message = self::message($alert);
        $subject = trim((string)$config['subject_prefix'] . ' ' . (string)($alert['title'] ?? $alert['code'] ?? 'Оповещение'));
        $delivered = 0;
        $failed = 0;

        foreach ($bitrixUsers as $userId) {
            try {
                self::sendBitrixIm($userId, $message, (int)$alert['id']);
                self::recordDelivery((int)$alert['id'], 'bitrix_im', (string)$userId, 'delivered');
                $delivered++;
            } catch (Throwable $e) {
                error_log('SiteBuilder alert IM delivery failed: ' . $e->getMessage());
                self::recordDelivery((int)$alert['id'], 'bitrix_im', (string)$userId, 'failed', self::errorCode($e));
                $failed++;
            }
        }

        foreach ($emails as $email) {
            try {
                self::sendEmail($email, $subject, $message);
                self::recordDelivery((int)$alert['id'], 'email', $email, 'delivered');
                $delivered++;
            } catch (Throwable $e) {
                error_log('SiteBuilder alert email delivery failed: ' . $e->getMessage());
                self::recordDelivery((int)$alert['id'], 'email', $email, 'failed', self::errorCode($e));
                $failed++;
            }
        }

        return ['enabled' => true, 'delivered' => $delivered, 'failed' => $failed];
    }

    private static function claimWindow(int $alertId, int $cooldownSeconds): bool
    {
        $stmt = sb_db()->prepare("\n            UPDATE sitebuilder.system_alert\n            SET last_notified_at=NOW(),updated_at=NOW()\n            WHERE id=:id\n              AND (last_notified_at IS NULL OR last_notified_at < NOW() - (CAST(:seconds AS integer) * INTERVAL '1 second'))\n            RETURNING id\n        ");
        $stmt->execute([':id' => $alertId, ':seconds' => $cooldownSeconds]);
        return (bool)$stmt->fetchColumn();
    }

    private static function sendBitrixIm(int $userId, string $message, int $alertId): void
    {
        if ($userId <= 0 || !Loader::includeModule('im') || !class_exists('CIMNotify')) {
            throw new RuntimeException('BITRIX_IM_UNAVAILABLE');
        }
        $result = \CIMNotify::Add([
            'TO_USER_ID' => $userId,
            'FROM_USER_ID' => 0,
            'NOTIFY_TYPE' => defined('IM_NOTIFY_SYSTEM') ? IM_NOTIFY_SYSTEM : 4,
            'NOTIFY_MODULE' => 'sitebuilder',
            'NOTIFY_TAG' => 'SITEBUILDER|ALERT|' . $alertId,
            'NOTIFY_MESSAGE' => htmlspecialcharsbx($message),
        ]);
        if (!$result) {
            throw new RuntimeException('BITRIX_IM_SEND_FAILED');
        }
    }

    private static function sendEmail(string $email, string $subject, string $message): void
    {
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = function_exists('bxmail')
            ? bxmail($email, $subject, $message, $headers)
            : mail($email, $subject, $message, $headers);
        if (!$sent) {
            throw new RuntimeException('EMAIL_SEND_FAILED');
        }
    }

    private static function recordDelivery(
        int $alertId,
        string $channel,
        string $recipient,
        string $status,
        string $errorCode = ''
    ): void {
        sb_db_execute("\n            INSERT INTO sitebuilder.system_alert_delivery (alert_id,channel,recipient,status,error_code,attempted_at,delivered_at)\n            VALUES (:alert_id,:channel,:recipient,:status,:error_code,NOW(),:delivered_at)\n        ", [
            ':alert_id' => $alertId,
            ':channel' => $channel,
            ':recipient' => mb_substr($recipient, 0, 255),
            ':status' => $status,
            ':error_code' => $errorCode !== '' ? mb_substr($errorCode, 0, 120) : null,
            ':delivered_at' => $status === 'delivered' ? (new DateTimeImmutable())->format('c') : null,
        ]);
    }

    private static function message(array $alert): string
    {
        $lines = [
            (string)($alert['title'] ?? $alert['code'] ?? 'SiteBuilder alert'),
            'Код: ' . (string)($alert['code'] ?? ''),
            'Уровень: ' . (string)($alert['severity'] ?? ''),
        ];
        if (!empty($alert['siteId'])) {
            $lines[] = 'Сайт: #' . (int)$alert['siteId'];
        }
        if (!empty($alert['sourceType'])) {
            $lines[] = 'Источник: ' . (string)$alert['sourceType'] . (!empty($alert['sourceId']) ? ' #' . (int)$alert['sourceId'] : '');
        }
        return implode("\n", $lines);
    }

    private static function severityAllowed(string $severity, string $minimum): bool
    {
        $rank = ['info' => 0, 'warning' => 1, 'critical' => 2];
        return ($rank[$severity] ?? 0) >= ($rank[$minimum] ?? 2);
    }

    private static function errorCode(Throwable $error): string
    {
        $message = strtoupper(trim($error->getMessage()));
        return preg_match('/^[A-Z][A-Z0-9_]{2,119}/', $message, $match) ? $match[0] : 'DELIVERY_FAILED';
    }

    private static function config(): array
    {
        static $config;
        if (is_array($config)) {
            return $config;
        }
        $defaults = [
            'external_delivery_enabled' => false,
            'minimum_severity' => 'critical',
            'cooldown_seconds' => 3600,
            'bitrix_user_ids' => [],
            'email_addresses' => [],
            'subject_prefix' => '[SiteBuilder]',
        ];
        $path = dirname(__DIR__) . '/config/notifications.php';
        $loaded = is_file($path) ? require $path : [];
        $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
        return $config;
    }
}
