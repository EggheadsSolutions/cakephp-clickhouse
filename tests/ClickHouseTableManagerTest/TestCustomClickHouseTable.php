<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\ClickHouseTableManagerTest;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class TestCustomClickHouseTable extends AbstractClickHouseTable
{
    /** @inheritdoc */
    public const TABLE = 'testSimple';
}
