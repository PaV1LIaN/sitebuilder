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

/*
 * Searching users is a read operation.
 * Do not call ensureExistsForBlock() on every search request: if settings
 * already exist, read them without any initialization/upsert side effects.
 */
$settings = DiskSettingsRepository::getByBlockId(
    $context->blockId
);

if (!is_array($settings)) {
    $settings = DiskSettingsRepository::ensureExistsForBlock(
        $context->blockId,
        $context->siteId,
        $context->pageId,
        $context->currentUserId
    );
}

$rootFolderId = (int)DiskRootResolver::resolve(
    $context,
    $settings
);

$folderId = (int)(
    $data['folderId']
    ?? $rootFolderId
);

DiskValidator::assertFolderInsideRoot(
    $folderId,
    $rootFolderId,
    $context
);

$permissions = DiskPermissionService::resolve(
    $context,
    $settings,
    $folderId,
    $rootFolderId
);

DiskValidator::assertCan(
    $permissions,
    'canManageAccess'
);

$query = trim(
    preg_replace(
        '/\s+/u',
        ' ',
        (string)($data['query'] ?? '')
    )
);

if (
    $query === ''
    || (
        !ctype_digit($query)
        && mb_strlen($query, 'UTF-8') < 2
    )
) {
    DiskResponse::success([
        'users' => [],
    ]);
}

$usersById = [];

$appendUser = static function (
    array $row
) use (&$usersById): void {
    $userId = (int)($row['ID'] ?? 0);

    if (
        $userId <= 0
        || isset($usersById[$userId])
        || count($usersById) >= 20
    ) {
        return;
    }

    $name = trim(
        implode(
            ' ',
            array_filter([
                (string)($row['LAST_NAME'] ?? ''),
                (string)($row['NAME'] ?? ''),
                (string)($row['SECOND_NAME'] ?? ''),
            ], static function ($value): bool {
                return trim((string)$value) !== '';
            })
        )
    );

    $usersById[$userId] = [
        'id' => $userId,
        'name' => $name !== ''
            ? $name
            : (string)($row['LOGIN'] ?? ('ID ' . $userId)),
        'login' => (string)($row['LOGIN'] ?? ''),
        'email' => (string)($row['EMAIL'] ?? ''),
    ];
};

if (ctype_digit($query)) {
    /*
     * Numeric query keeps the fast exact-ID path.
     */
    $result = CUser::GetByID(
        (int)$query
    );

    $row = $result
        ? $result->Fetch()
        : null;

    if (
        is_array($row)
        && (string)($row['ACTIVE'] ?? '') === 'Y'
    ) {
        $appendUser($row);
    }
} else {
    /*
     * CUser::GetList does NOT use D7-style filter keys such as %LOGIN.
     * Use the ORM here, where %FIELD is the supported substring operator.
     *
     * One query searches all relevant identity fields with OR semantics.
     */
    $result = \Bitrix\Main\UserTable::getList([
        'select' => [
            'ID',
            'LOGIN',
            'EMAIL',
            'NAME',
            'LAST_NAME',
            'SECOND_NAME',
            'ACTIVE',
        ],
        'filter' => [
            '=ACTIVE' => 'Y',
            [
                'LOGIC' => 'OR',
                '%LOGIN' => $query,
                '%EMAIL' => $query,
                '%NAME' => $query,
                '%LAST_NAME' => $query,
                '%SECOND_NAME' => $query,
            ],
        ],
        'order' => [
            'LAST_NAME' => 'ASC',
            'NAME' => 'ASC',
            'ID' => 'ASC',
        ],
        'limit' => 20,
    ]);

    while ($row = $result->fetch()) {
        if (is_array($row)) {
            $appendUser($row);
        }

        if (count($usersById) >= 20) {
            break;
        }
    }
}

DiskResponse::success([
    'users' => array_values(
        $usersById
    ),
]);
