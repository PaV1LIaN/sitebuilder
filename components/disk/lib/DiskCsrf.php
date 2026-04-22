<?php

class DiskCsrf
{
    public static function validateFromRequest(): void
    {
        $sessid = '';

        if (isset($_POST['sessid'])) {
            $sessid = (string)$_POST['sessid'];
        } elseif (isset($_REQUEST['sessid'])) {
            $sessid = (string)$_REQUEST['sessid'];
        } else {
            $json = disk_read_json_body();
            if (isset($json['sessid'])) {
                $sessid = (string)$json['sessid'];
            }
        }

        if ($sessid === '') {
            throw new RuntimeException('EMPTY_SESSID');
        }

        if (!check_bitrix_sessid($sessid)) {
            throw new RuntimeException('BAD_SESSID');
        }
    }
}