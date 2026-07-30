<?php

global $USER;

if ($action === 'ping') {
    sb_json_ok([
        'pong' => true,
        'handler' => 'common',
        'userId' => (int)$USER->GetID(),
        'login' => (string)$USER->GetLogin(),
        'time' => date('c'),
    ]);
}

sb_json_error('NOT_MOVED_YET', 501, [
    'handler' => 'common',
    'action' => $action,
]);