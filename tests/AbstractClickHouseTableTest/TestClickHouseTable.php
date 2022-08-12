<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

/**
 * Тестовая таблица, которая используется только для юнит тестов
 */
class TestClickHouseTable extends AbstractClickHouseTable
{
    /** @inerhitDoc */
    //public const TABLE = 'testTable';

    /** @inerhitDoc */
    public const WRITER_CONFIG = 'ssdNode';
}
