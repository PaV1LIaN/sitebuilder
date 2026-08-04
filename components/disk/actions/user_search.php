<?php

DiskCsrf::validateFromRequest();
$data = disk_read_json_body();
$currentUserId = DiskCurrentUser::requireId();

$context = DiskContextFactory::fromArray([
    'siteId' => (int)($data['siteId'] ?? 0),
    'pageId' => (int)($data['pageId'] ?? 0),
    'blockId' => (int)($data['blockId'] ?? 0),
    'currentUserId' => $currentUserId,
]);

DiskValidator::assertContext($context);
$settings = DiskSettingsRepository::ensureExistsForBlock(
    $context->blockId,
    $context->siteId,
    $context->pageId,
    $context->currentUserId
);
$rootFolderId = (int)DiskRootResolver::resolve($context, $settings);
$folderId = (int)($data['folderId'] ?? $rootFolderId);
DiskValidator::assertFolderInsideRoot($folderId, $rootFolderId, $context);
$permissions = DiskPermissionService::resolve($context, $settings, $folderId, $rootFolderId);
DiskValidator::assertCan($permissions, 'canManageAccess');

$query = trim((string)($data['query'] ?? ''));
if ($query === '' || (!ctype_digit($query) && mb_strlen($query, 'UTF-8') < 2)) {
    DiskResponse::success(['users' => []]);
}

$usersById = [];
$appendUser = static function (array $row) use (&$usersById): void {
    $userId = (int)($row['ID'] ?? 0);
    if ($userId <= 0 || isset($usersById[$userId]) || count($usersById) >= 20) {
        return;
    }

    $name = trim(implode(' ', array_filter([
        (string)($row['LAST_NAME'] ?? ''),
        (string)($row['NAME'] ?? ''),
        (string)($row['SECOND_NAME'] ?? ''),
    ])));

    $usersById[$userId] = [
        'id' => $userId,
        'name' => $name !== '' ? $name : (string)($row['LOGIN'] ?? ('ID ' . $userId)),
        'login' => (string)($row['LOGIN'] ?? ''),
        'email' => (string)($row['EMAIL'] ?? ''),
    ];
};

$fields = ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'ACTIVE'];

if (ctype_digit($query)) {
    $result = CUser::GetByID((int)$query);
    $row = $result ? $result->Fetch() : null;
    if ($row && (string)($row['ACTIVE'] ?? '') === 'Y') {
        $appendUser($row);
    }
} else {
    $filters = [
        ['ACTIVE' => 'Y', '%LOGIN' => $query],
        ['ACTIVE' => 'Y', '%EMAIL' => $query],
        ['ACTIVE' => 'Y', '%NAME' => $query],
        ['ACTIVE' => 'Y', '%LAST_NAME' => $query],
        ['ACTIVE' => 'Y', '%SECOND_NAME' => $query],
    ];

    foreach ($filters as $filter) {
        $by = 'last_name';
        $order = 'asc';
        $result = CUser::GetList($by, $order, $filter, [
            'FIELDS' => $fields,
            'NAV_PARAMS' => ['nTopCount' => 20],
        ]);

        while ($row = $result->Fetch()) {
            $appendUser($row);

            if (count($usersById) >= 20) {
                break 2;
            }
        }
    }
}

DiskResponse::success(['users' => array_values($usersById)]);
