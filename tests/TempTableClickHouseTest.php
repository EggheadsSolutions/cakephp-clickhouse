<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\I18n\FrozenTime;
use Cake\TestSuite\TestCase;
use ClickHouseDB\Exception\QueryException;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\TempTableClickHouse;

class TempTableClickHouseTest extends TestCase
{
    private const CH_PROFILE = 'writer';

    /**
     * @testdox Проверим создание и заполнение временной таблицы
     * @see
     */
    public function test(): void
    {
        FrozenTime::setTestNow('2022-04-22 18:43:00');
        $set = new TempTableClickHouse('Set', ['id' => 'int', 'String'], "SELECT :id, '1'", ['id' => 123], self::CH_PROFILE);
        $tableName = $set->getName();
        $this->assertTextContains('tempSet_220422184300_0', $tableName);

        $result = ClickHouse::getInstance(self::CH_PROFILE)->select('SELECT * FROM ' . $tableName)->rows();
        self::assertCount(1, $result);
        self::assertEquals(['id' => 123, 'field0' => 1], $result[0]);
    }

    /**
     * @testdox Проверим автоудаление таблицы
     * @return void
     */
    public function testRemoveTable(): void
    {
        $set = new TempTableClickHouse('Set', ['String'], "SELECT '1'", [], self::CH_PROFILE);
        $tableName = $set->getName();

        unset($set);
        $this->expectExceptionCode('404');
        $this->expectException(QueryException::class);
        ClickHouse::getInstance(self::CH_PROFILE)->select("DESCRIBE " . $tableName)->fetchOne();
    }
}
