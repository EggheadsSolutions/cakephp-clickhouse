<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Eggheads\CakephpClickHouse\AbstractClickHouseFixtureFactory;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class TestClickhouseFixtureFactory extends AbstractClickHouseFixtureFactory
{
    /**
     * @inheritDoc
     *
     * @return array<string, string|float>
     */
    protected function _getDefaultData(): array
    {
        return [
            'id' => 'String',
            'url' => 'String',
            'data' => 10.2,
            'checkDate' => '2022-01-03',
            'created' => '2020-03-01 23:12:12',
        ];
    }

    /** @inheritdoc */
    protected function _getTable(): AbstractClickHouseTable
    {
        return TestClickHouseTable::getInstance();
    }
}
