<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\I18n\FrozenTime;
use Cake\TestSuite\TestCase;
use ClickHouseDB\Exception\QueryException;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\QueryClickHouseSet;

class QueryClickHouseSetTest extends TestCase
{
    private const CH_PROFILE = 'writer';

    /** Тест класса сета */
    public function test(): void
    {
        FrozenTime::setTestNow('2022-04-22 18:43:00');
        $set = new QueryClickHouseSet(['String'], "SELECT '1'", self::CH_PROFILE);
        self::assertEquals('tempSet220422184300_0', $set->getName());

        $tableName = $set->getName();
        $clickhouse = ClickHouse::getInstance(self::CH_PROFILE);
        self::assertTrue((bool)$clickhouse->select("SELECT '1' IN " . $tableName . " as isValid")
            ->fetchOne('isValid'));

        self::assertFalse((bool)$clickhouse->select("SELECT '0' IN " . $tableName . " as isValid")
            ->fetchOne('isValid'));

        // таблица не найдена
        unset($set);
        $this->expectExceptionCode('404');
        $this->expectException(QueryException::class);
        print_r($clickhouse->select("DESCRIBE " . $tableName)->fetchOne());
    }
}
