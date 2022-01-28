<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

/**
 * Тестовая таблица, которая используется только для юнит тестов
 */
class TestClickHouseTable extends AbstractClickHouseTable
{
    public const TABLE = 'testTable';
    public const WRITER_CONFIG = 'ssdNode';

    protected function _getClickHouseConfig(string $profile): array
    {
        return CLICKHOUSE_CONFIG;
    }
}
