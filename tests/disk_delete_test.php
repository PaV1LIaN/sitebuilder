<?php

namespace Bitrix\Disk {
    class File
    {
        public static array $objects = [];
        public bool $result = true;
        public array $errors = [];
        public ?\Throwable $failure = null;
        public int $actor = 0;

        public static function loadById(int $id): ?self
        {
            return static::$objects[$id] ?? null;
        }

        public function markDeleted(int $actor): bool
        {
            $this->actor = $actor;
            if ($this->failure) {
                throw $this->failure;
            }
            return $this->result;
        }

        public function getErrors(): array
        {
            return $this->errors;
        }
    }

    class Folder extends File
    {
        public static array $objects = [];
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    require __DIR__ . '/../components/disk/lib/DiskContext.php';
    require __DIR__ . '/../components/disk/lib/DiskBitrixStorageAdapter.php';

    function assertSameValue($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ': ' . var_export($actual, true));
        }
    }

    $adapter = new \DiskBitrixStorageAdapter(42);
    $context = new \DiskContext(1, 2, 3, 42);

    $first = new \Bitrix\Disk\File();
    \Bitrix\Disk\File::$objects[100] = $first;
    assertSameValue(
        [['entityType' => 'file', 'id' => 100]],
        $adapter->delete($context, [['entityType' => 'file', 'id' => 100]]),
        'Successfully deleted file should be returned'
    );
    assertSameValue(42, $first->actor, 'Deletion must use the current user ID');

    $locked = new \Bitrix\Disk\File();
    $locked->result = false;
    $locked->errors = [new \RuntimeException('Файл заблокирован')];
    \Bitrix\Disk\File::$objects[101] = $locked;
    try {
        $adapter->delete($context, [['entityType' => 'file', 'id' => 101]]);
        throw new \RuntimeException('Expected a failed Bitrix markDeleted result to raise an error');
    } catch (\DiskDeleteException $e) {
        assertSameValue('file', $e->getEntityType(), 'Type should be in delete error');
        assertSameValue(101, $e->getEntityId(), 'ID should be in delete error');
        assertSameValue([], $e->getDeletedItems(), 'No items should be reported deleted');
        if (strpos($e->getPrevious()->getMessage(), 'Файл заблокирован') === false) {
            throw new \RuntimeException('Bitrix errors must be preserved');
        }
    }

    $later = new \Bitrix\Disk\Folder();
    $later->failure = new \RuntimeException('DELETE_EVENT_FAILED');
    \Bitrix\Disk\Folder::$objects[200] = $later;
    try {
        $adapter->delete($context, [
            ['entityType' => 'file', 'id' => 100],
            ['entityType' => 'folder', 'id' => 200],
        ]);
        throw new \RuntimeException('Expected event failure to be propagated');
    } catch (\DiskDeleteException $e) {
        assertSameValue('folder', $e->getEntityType(), 'Should identify the failed folder');
        assertSameValue(200, $e->getEntityId(), 'Should identify the failed folder ID');
        assertSameValue(
            [['entityType' => 'file', 'id' => 100]],
            $e->getDeletedItems(),
            'Should preserve partial success during bulk deletion'
        );
    }

    echo "Disk delete adapter checks passed\n";
}
