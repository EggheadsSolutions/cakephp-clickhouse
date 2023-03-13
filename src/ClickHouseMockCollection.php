<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

/**
 * Список из временных таблиц, подменяющих в тестах на время выполнения запроса основные таблицы
 */
class ClickHouseMockCollection
{
    /**
     * @var array<string, TempTableClickHouse>
     */
    private static array $_collection = [];

    /**
     * Почистим список замоканных таблиц
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$_collection = [];
    }

    /**
     * Добавим в коллекцию новую таблицу
     *
     * @param string $tableName
     * @param TempTableClickHouse $instance
     * @return void
     */
    public static function add(string $tableName, TempTableClickHouse $instance): void
    {
        self::$_collection[$tableName] = $instance;
    }

    /**
     * Получить экземпляр временной таблицы, используемой как подстановка для основной таблицы
     *
     * @param string $tableName
     * @return TempTableClickHouse|null
     */
    public static function getMockTable(string $tableName): ?TempTableClickHouse
    {
        return self::$_collection[$tableName] ?? null;
    }
}
