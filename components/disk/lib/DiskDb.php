<?php



class DiskDb
{

    protected static ?PDO $pdo = null;

    public static function setConnection(PDO $pdo): void
    {

        self::$pdo = $pdo;
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

		require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/lib/pg_master.php';
		$pdo = getPdo();
		self::setConnection($pdo);
		return self::$pdo;

        /*
         * ВАЖНО:
         * Здесь подставь свой реальный способ подключения к БД.
         *
         * Вариант 1:

         * 
         *
         * Вариант 2:
         * Инициализируй DiskDb::setConnection($pdo) заранее в bootstrap проекта.
         */

        throw new RuntimeException('DB_CONNECTION_NOT_CONFIGURED');
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): bool
    {
        $stmt = self::getConnection()->prepare($sql);
        return $stmt->execute($params);
    }

    public static function lastInsertId(?string $name = null): string
    {
        return self::getConnection()->lastInsertId($name);
    }
}