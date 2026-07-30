<?php

require_once __DIR__ . '/db.php';

/**
 * Выделение ID через PostgreSQL sequence.
 * Sequence не откатывается вместе с транзакцией, поэтому пропуски ID допустимы
 * и являются нормальным поведением PostgreSQL.
 */
final class IdSequenceService
{
    public const ENTITY_SITE = 'site';
    public const ENTITY_PAGE = 'page';
    public const ENTITY_BLOCK = 'block';
    public const ENTITY_MENU = 'menu';

    private const SEQUENCES = [
        self::ENTITY_SITE => 'sitebuilder.site_id_seq',
        self::ENTITY_PAGE => 'sitebuilder.page_id_seq',
        self::ENTITY_BLOCK => 'sitebuilder.block_id_seq',
        self::ENTITY_MENU => 'sitebuilder.menu_id_seq',
    ];

    public static function next(string $entityType): int
    {
        $ids = self::reserve($entityType, 1);
        return $ids[0];
    }

    /** @return int[] */
    public static function reserve(string $entityType, int $count): array
    {
        if ($count <= 0 || $count > 10000) {
            throw new InvalidArgumentException('INVALID_ID_RESERVATION_COUNT');
        }

        $sequence = self::sequence($entityType);
        $stmt = sb_db()->prepare(
            "SELECT nextval('{$sequence}'::regclass) AS id FROM generate_series(1, CAST(:count AS integer))"
        );
        $stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $stmt->execute();

        $ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        if (count($ids) !== $count || min($ids) <= 0 || count(array_unique($ids)) !== $count) {
            throw new RuntimeException('ID_SEQUENCE_ALLOCATION_FAILED');
        }

        return $ids;
    }

    public static function sequence(string $entityType): string
    {
        $entityType = strtolower(trim($entityType));
        if (!isset(self::SEQUENCES[$entityType])) {
            throw new InvalidArgumentException('INVALID_SEQUENCE_ENTITY_TYPE');
        }

        return self::SEQUENCES[$entityType];
    }
}
