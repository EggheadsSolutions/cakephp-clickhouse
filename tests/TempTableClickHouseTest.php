<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\I18n\FrozenTime;
use Cake\TestSuite\TestCase;
use ClickHouseDB\Exception\DatabaseException;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\TempTableClickHouse;
use Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest\TestClickHouseTable;

/**
 * @SuppressWarnings(PHPMD.MethodMix)
 */
class TempTableClickHouseTest extends TestCase
{
    private const CH_PROFILE = 'writer';

    /** @inerhitDoc */
    public function setUp(): void
    {
        Cache::disable();
        parent::setUp();
    }

    /**
     * @testdox Проверим создание и заполнение временной таблицы
     */
    public function test(): void
    {
        FrozenTime::setTestNow('2022-04-22 18:43:00');
        $set = new TempTableClickHouse('Set', ['id' => 'int', 'String'], "SELECT :id, '1'", ['id' => 123], self::CH_PROFILE);
        $tableName = $set->getName();
        self::assertStringStartsWith('default.tempSet_220422184300_', $tableName);

        $result = ClickHouse::getInstance(self::CH_PROFILE)->select('SELECT * FROM ' . $tableName)->rows();
        self::assertCount(1, $result);
        self::assertEquals(['id' => 123, 'field0' => 1], $result[0]);

        /** Проверяем что префикс таблицы переопределяется при наличии в конфигурацц */
        Configure::write('tempTableClickHousePrefix', 'test');
        $set = new TempTableClickHouse('Set', ['id' => 'int', 'String'], "SELECT :id, '1'", ['id' => 123], self::CH_PROFILE);
        $tableName = $set->getName();
        self::assertStringStartsWith('default.testSet_220422184300_', $tableName);
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
        $this->expectExceptionCode('60');
        $this->expectException(DatabaseException::class);
        ClickHouse::getInstance(self::CH_PROFILE)->select("DESCRIBE " . $tableName)->fetchOne();
    }

    /**
     * @testdox Проверим создание по образу и подобию
     * @return void
     */
    public function testCloneTable(): void
    {
        $table = TempTableClickHouse::createFromTable(
            'clone',
            TestClickHouseTable::getInstance(),
            "SELECT '1', 'bla-bla', 3.0, '2020-08-04', '2020-08-04 09:00:00'"
        );

        self::assertEquals(
            [[
                 'id' => '1',
                 'url' => 'bla-bla',
                 'data' => 3.0,
                 'checkDate' => '2020-08-04',
                 'created' => '2020-08-04 09:00:00',
             ]],
            ClickHouse::getInstance()->select('SELECT * FROM ' . $table->getName())->rows()
        );
    }
}
