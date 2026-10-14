<?php

declare(strict_types=1);

/** Console adapter; writes native ACL, never a shadow copy of it in Sitebuilder. */
final class AccessNativeDiskService
{
    private const ROLE_KEYS = ['VIEWER' => 'read', 'ADD' => 'add', 'EDITOR' => 'edit', 'FULL' => 'full'];

    public static function direct(array $snapshot): array
    {
        $roles = [];
        foreach ($snapshot['taskDefinitions'] as $task) {
            $roles[(int)$task['id']] = array_search($task['key'], self::ROLE_KEYS, true) ?: 'CUSTOM';
        }
        $result = [];
        foreach ($snapshot['byAccessCode'] as $code => $rights) {
            $role = 'CUSTOM';
            if (count($rights) === 1) {
                $right = $rights[0];
                $role = $roles[(int)$right['TASK_ID']] ?? 'CUSTOM';
                if ($right['NEGATIVE']) { $role = $role === 'FULL' ? 'DENY' : 'CUSTOM'; }
            }
            $result[$code] = ['role' => $role, 'nativeRights' => $rights];
        }
        return $result;
    }

    public static function requests(array $changes, array $snapshot): array
    {
        $names = [];
        foreach ($snapshot['taskDefinitions'] as $name => $task) { $names[$task['key']] = $name; }
        $requests = [];
        foreach ($changes as $change) {
            $role = $change['role'];
            $name = $role === 'INHERIT' ? BitrixDiskRightsService::INHERIT
                : ($role === 'DENY' ? BitrixDiskRightsService::NONE : ($names[self::ROLE_KEYS[$role] ?? ''] ?? ''));
            if ($name === '') { throw new AccessAdminException('INVALID_CHANGE_ROLE', ['userId' => $change['id']]); }
            $requests[] = ['userId' => $change['id'], 'taskName' => $name];
        }
        return $requests;
    }

    /** Pending receipt survives a lost acknowledgement between two independent databases. */
    public static function recoveryState(array $journal, string $currentRevision): string
    {
        if (hash_equals((string)$journal['afterRevision'], $currentRevision)) { return 'applied'; }
        if (hash_equals((string)$journal['beforeRevision'], $currentRevision)) { return 'not_applied'; }
        return 'review_required';
    }

    private static function lockedTarget(array $input, int $actor, int $folderId): array
    {
        $target = AccessAdminService::target($input, $actor);
        RequestLockService::lockMutation('pageAccess.save', $target);
        sb_access_lock_site(sb_db(), $target['siteId']);
        // Protect page/block lifetime and settings for the native write phase.
        $stmt = sb_db()->prepare('SELECT b.id FROM sitebuilder.block b JOIN sitebuilder.page p ON p.id=b.page_id
            WHERE b.id=:block AND p.id=:page AND p.site_id=:site FOR SHARE OF b,p');
        $stmt->execute([':block' => $target['blockId'], ':page' => $target['pageId'], ':site' => $target['siteId']]);
        if (!$stmt->fetchColumn()) { throw new AccessAdminException('PAGE_NOT_IN_SITE'); }
        $target = AccessAdminService::target($input, $actor);
        if ($target['mode'] !== 'bitrix_disk' || !$target['editable'] || $target['folderId'] !== $folderId) {
            throw new AccessAdminException('ACCESS_VERSION_CONFLICT', [], 409);
        }
        return $target;
    }

    public static function apply(array $input, int $actor, array $target): array
    {
        if (!AccessUserIdentity::isPortalAdmin($actor)) { throw new AccessAdminException('NATIVE_PORTAL_ADMIN_REQUIRED', [], 403); }
        $pdo = sb_db();
        if ($pdo->inTransaction()) { throw new AccessAdminException('NATIVE_NESTED_TRANSACTION_UNSUPPORTED', [], 500); }
        $folderId = (int)$target['folderId'];
        $lock = DiskRightsLock::acquire($folderId);
        $started = false; $journal = []; $profiles = [];
        $hash = hash('sha256', json_encode([$actor, $input], JSON_THROW_ON_ERROR));
        try {
            $started = sb_db_transaction_scope_begin();
            $target = self::lockedTarget($input, $actor, $folderId);
            $stmt = $pdo->prepare("SELECT id,details_json FROM sitebuilder.audit_log
                WHERE request_id=:request AND site_id=:site AND action='accessAdmin.native' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':request' => $input['requestId'], ':site' => $target['siteId']]);
            $previous = $stmt->fetch(PDO::FETCH_ASSOC);
            $snapshot = BitrixDiskRightsService::getDirectSnapshot($folderId);
            $direct = self::direct($snapshot);
            if ($previous) {
                $journal = sb_json_decode_assoc($previous['details_json']);
                if (!hash_equals((string)($journal['hash'] ?? ''), $hash)) { throw new AccessAdminException('REQUEST_ID_REUSED', [], 409); }
                if (($journal['state'] ?? '') === 'complete') {
                    sb_db_transaction_scope_commit($started); $started = false;
                    return $journal['receipt'] + ['replayed' => true];
                }
            } elseif (!hash_equals(AccessAdminService::revision($target, $direct), $input['revision'])) {
                throw new AccessAdminException('ACCESS_VERSION_CONFLICT', [], 409);
            }
            $changes = AccessAdminService::normalizeChanges($target, (array)($input['changes'] ?? []), $direct);
            $profiles = AccessUserResolver::profiles(array_keys($changes));
            // A completed native write only needs its receipt finalized. Do not reject
            // recovery because a subject became inactive after that successful write.
            $alreadyApplied = $previous && self::recoveryState($journal, $snapshot['rightsRevision']) === 'applied';
            $errors = [];
            foreach ($changes as $id => $change) {
                if ($alreadyApplied) { continue; }
                $problem = null;
                if (AccessUserIdentity::isPortalAdmin($id) || sitebuilder_is_site_admin_user_id($id)) {
                    $problem = 'PROTECTED_ADMIN_ASSIGNMENT';
                } elseif (!$change['remove'] && (empty($profiles[$id]['active']) || $id === (int)(sitebuilder_auth_config()['guest_user_id'] ?? 0))) {
                    $problem = 'USER_NOT_ACTIVE';
                }
                if ($problem) { $errors[] = ['userId' => $id, 'name' => $profiles[$id]['name'] ?? ('ID ' . $id), 'error' => $problem]; }
            }
            if ($errors) { throw new AccessAdminException('USER_VALIDATION_FAILED', ['users' => $errors]); }
            $requested = self::requests($changes, $snapshot);
            if (!$previous) {
                $plan = BitrixDiskRightsService::planSelectedRights($requested, $snapshot);
                $after = $snapshot;
                $after['byAccessCode'] = [];
                foreach ($plan['afterRights'] as $right) { $after['byAccessCode'][$right['ACCESS_CODE']][] = $right; }
                $afterDirect = self::direct($after);
                $auditUsers = [];
                foreach ($changes as $id => $change) {
                    $auditUsers[] = ['userId' => $id, 'name' => $profiles[$id]['name'] ?? ('ID ' . $id),
                        'before' => $direct['U' . $id] ?? null, 'after' => $afterDirect['U' . $id] ?? null];
                }
                $journal = ['hash' => $hash, 'state' => 'pending', 'target' => $target,
                    'beforeRevision' => $snapshot['rightsRevision'], 'afterRevision' => $plan['afterRevision'],
                    'users' => $auditUsers, 'requests' => $requested];
                $stmt = $pdo->prepare("INSERT INTO sitebuilder.audit_log
                    (request_id,site_id,actor_user_id,actor_access_code,action,entity_type,entity_id,page_id,details_json)
                    VALUES (:request,:site,:actor,:code,'accessAdmin.native',:kind,:entity,:page,CAST(:details AS jsonb)) RETURNING id");
                $stmt->execute([':request' => $input['requestId'], ':site' => $target['siteId'], ':actor' => $actor,
                    ':code' => 'U' . $actor, ':kind' => $target['kind'], ':entity' => $folderId, ':page' => $target['pageId'],
                    ':details' => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                $journalId = (int)$stmt->fetchColumn();
            } else { $journalId = (int)$previous['id']; }
            // Write-ahead receipt must be durable BEFORE the native transaction starts.
            sb_db_transaction_scope_commit($started); $started = false;
            $started = sb_db_transaction_scope_begin();
            $target = self::lockedTarget($input, $actor, $folderId);
            $snapshot = BitrixDiskRightsService::getDirectSnapshot($folderId);
            $recovery = self::recoveryState($journal, $snapshot['rightsRevision']);
            if ($recovery === 'review_required' || ($alreadyApplied && $recovery !== 'applied')) {
                throw new AccessAdminException('NATIVE_RECONCILIATION_REQUIRED', ['folderId' => $folderId, 'requestId' => $input['requestId']], 500);
            }
            if ($recovery === 'not_applied') {
                $snapshot = BitrixDiskRightsService::saveSelectedRights($folderId, $journal['requests'], $journal['beforeRevision']);
            }
            $receipt = ['saved' => count($changes), 'userIds' => array_keys($changes), 'requestId' => $input['requestId'],
                'revision' => AccessAdminService::revision($target, self::direct($snapshot)),
                'recovered' => $previous !== false && $recovery === 'applied'];
            $journal['state'] = 'complete'; $journal['receipt'] = $receipt;
            $stmt = $pdo->prepare('UPDATE sitebuilder.audit_log SET details_json=CAST(:details AS jsonb) WHERE id=:id');
            $stmt->execute([':details' => json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ':id' => $journalId]);
            sb_db_transaction_scope_commit($started); $started = false;
            return $receipt;
        } catch (Throwable $error) {
            if ($started) { sb_db_transaction_scope_rollback($started); }
            if ($error instanceof DiskRightsBatchException) {
                error_log('Sitebuilder native packet [' . $input['requestId'] . '] ' . ($error->getPrevious()?->getMessage() ?? $error->getMessage()));
                $users = [];
                foreach ($error->userIds as $id) {
                    $users[] = ['userId' => $id, 'name' => $profiles[$id]['name'] ?? ('ID ' . $id),
                        'groups' => AccessUserIdentity::groups((int)$id), 'error' => 'NATIVE_PACKET_' . strtoupper($error->phase)];
                }
                throw new AccessAdminException('NATIVE_PACKET_FAILED', ['phase' => $error->phase,
                    'folderId' => $folderId, 'requestId' => $input['requestId'], 'users' => $users], 500);
            }
            if ($error instanceof DiskRightsVersionConflictException) {
                throw new AccessAdminException('NATIVE_RECONCILIATION_REQUIRED', ['folderId' => $folderId, 'requestId' => $input['requestId']], 500);
            }
            throw $error;
        } finally {
            DiskRightsLock::release($lock);
        }
    }
}
