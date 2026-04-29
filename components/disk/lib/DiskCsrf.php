<?php

class DiskCsrf
{
    public static function validateFromRequest(): void
    {
        $sessid = self::extractSessid();

        if ($sessid === '') {
            throw new RuntimeException('EMPTY_SESSID');
        }

        $currentSessid = (string)bitrix_sessid();

        if ($currentSessid === '') {
            throw new RuntimeException('BAD_SESSID');
        }

        if (!hash_equals($currentSessid, $sessid)) {
            throw new RuntimeException('BAD_SESSID');
        }
    }

    protected static function extractSessid(): string
    {
        if (!empty($_POST['sessid'])) {
            return trim((string)$_POST['sessid']);
        }

        if (!empty($_REQUEST['sessid'])) {
            return trim((string)$_REQUEST['sessid']);
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['sessid'])) {
            return trim((string)$decoded['sessid']);
        }

        return '';
    }
}