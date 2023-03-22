<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\Core\Configure;
use Cake\I18n\FrozenTime;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\TempTableClickHouse;
use Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest\TestClickHouseTable;

class TempTableClickHouseTest extends TestCase
{
    /** @var string Профиль ClickHouse */
    private const CH_PROFILE = 'writer';

    /** @inerhitDoc */
    public function setUp(): void
    {
        parent::setUp();

        ClickHouse::getInstance('writer')->getClient()->write('
            CREATE TABLE default.test
            (
                id      String,
                value   Decimal(10, 2),
                created DateTime
            )
            ENGINE = MergeTree() ORDER BY id
        ');
    }

    /** @inheritdoc */
    protected function _dropClickHouseTables(): void
    {
        ClickHouse::getInstance('writer')->getClient()->write('DROP TABLE IF EXISTS default.test');
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

        /** Проверяем что префикс таблицы переопределяется при наличии в конфигурации */
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
        self::assertFalse(ClickHouse::getInstance(self::CH_PROFILE)->isTableExist($tableName));
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
            "SELECT '1', 3.0, '2020-08-04 09:00:00'"
        );

        self::assertEquals(
            [[
                 'id' => '1',
                 'value' => 3.0,
                 'created' => '2020-08-04 09:00:00',
             ]],
            ClickHouse::getInstance()->select('SELECT * FROM ' . $table->getName())->rows()
        );
    }
}
