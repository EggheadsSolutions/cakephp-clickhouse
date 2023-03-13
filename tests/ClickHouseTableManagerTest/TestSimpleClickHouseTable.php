<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\ClickHouseTableManagerTest;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class TestSimpleClickHouseTable extends AbstractClickHouseTable
{
    /** @inheritdoc */
    public const WRITER_CONFIG = 'writer';
}
