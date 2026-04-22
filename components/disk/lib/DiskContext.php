<?php

class DiskContext
{
    public int $siteId;
    public int $pageId;
    public int $blockId;
    public int $currentUserId;

    public function __construct(int $siteId, int $pageId, int $blockId, int $currentUserId)
    {
        $this->siteId = $siteId;
        $this->pageId = $pageId;
        $this->blockId = $blockId;
        $this->currentUserId = $currentUserId;
    }

    public function toArray(): array
    {
        return [
            'siteId' => $this->siteId,
            'pageId' => $this->pageId,
            'blockId' => $this->blockId,
            'currentUserId' => $this->currentUserId,
        ];
    }
}

class DiskContextFactory
{
    public static function fromArray(array $data): DiskContext
    {
        return new DiskContext(
            (int)($data['siteId'] ?? 0),
            (int)($data['pageId'] ?? 0),
            (int)($data['blockId'] ?? 0),
            (int)($data['currentUserId'] ?? 0)
        );
    }
}