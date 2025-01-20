<?php

namespace Tests\E2E\Adapter;

use PDO;
use Redis;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;

class PostgresTest extends Base
{
    public static ?Database $database = null;
    protected static ?PDO $pdo = null;
    protected static string $namespace;

    /**
     * Return name of adapter
     *
     * @return string
     */
    public static function getAdapterName(): string
    {
        return "postgres";
    }

    /**
     * @reture Adapter
     */
    public static function getDatabase(bool $fresh = false): Database
    {
        if (!is_null(self::$database) && !$fresh) {
            return self::$database;
        }

        $dbHost = 'postgres';
        $dbPort = '5432';
        $dbUser = 'root';
        $dbPass = 'password';

        $pdo = new PDO("pgsql:host={$dbHost};port={$dbPort};", $dbUser, $dbPass, Postgres::getPDOAttributes());
        try {
            $redis = new Redis();
            $redis->connect('redis', 6379);
            $redis->flushAll();
            $cache = new Cache(new RedisAdapter($redis));
        } catch (\Exception $e) {
            $cache = new Cache(new None());
        }

        $database = new Database(new Postgres($pdo), $cache);
        $database
            ->setDatabase('utopiaTests')
            ->setNamespace(static::$namespace = 'myapp_' . uniqid());

        if ($database->exists()) {
            $database->delete();
        }

        $database->create();

        self::$pdo = $pdo;
        return self::$database = $database;
    }

    protected static function deleteColumn(string $collection, string $column): bool
    {
        $sqlTable = '"' . self::getDatabase()->getDatabase() . '"."' . self::getDatabase()->getNamespace() . '_' . $collection . '"';
        $sql = "ALTER TABLE {$sqlTable} DROP COLUMN \"{$column}\"";

        self::$pdo->exec($sql);

        return true;
    }

    protected static function deleteIndex(string $collection, string $index): bool
    {
        $key = "\"".self::getDatabase()->getNamespace()."_".self::getDatabase()->getTenant()."_{$collection}_{$index}\"";

        $sql = "DROP INDEX \"".self::getDatabase()->getDatabase()."\".{$key}";

        self::$pdo->exec($sql);

        return true;
    }
}
