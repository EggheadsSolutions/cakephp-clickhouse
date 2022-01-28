<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\ClickHouse;

class TestClickHouseTableTest extends TestCase
{
    /**
     * @testdox Тест всего цикла функций
     *
     * @group clickHouse
     * @return void
     */
    public function test(): void
    {
        $testTable = TestClickHouseTable::getInstance();
        $schema = $testTable->getSchema();
        self::assertEquals([
            "id" => "String",
            "url" => "String",
            "data" => "Decimal(10, 2)",
            "created" => "DateTime",
        ], $schema);

        $testTable->truncate();

        $svData = [
            [
                'id' => '1',
                'url' => 'bla-bla',
                'data' => 3.0,
                'created' => '2020-08-04 09:00:00',
            ],
            [
                'id' => '2',
                'url' => 'ggggg',
                'data' => 5.0,
                'created' => '2020-08-02 09:00:00',
            ],
        ];
        $testTable->insert($svData);

        $selectAllQuery = 'SELECT * FROM ' . $testTable::TABLE;
        self::assertEquals($svData, $testTable->select($selectAllQuery)->rows());

        $testTable->deleteAll("id = '1'");
        sleep(1);
        self::assertEquals([$svData[1]], $testTable->select($selectAllQuery)->rows());

        $testTable->truncate();
        self::assertEmpty($testTable->select($selectAllQuery)->rows());
    }

    /** @inerhitDoc */
    protected function setUp(): void
    {
        parent::setUp();

        $writer = ClickHouse::getInstance(TestClickHouseTable::READER_CONFIG, CLICKHOUSE_CONFIG);
        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => TestClickHouseTable::TABLE]);
        $writer->getClient()->write(
            "CREATE TABLE {table}
            (
                id      String,
                url     String,
                data    Decimal(10, 2),
                created DateTime
            ) ENGINE = MergeTree() ORDER BY id",
            ['table' => TestClickHouseTable::TABLE]
        );

        Cache::disable();
    }
}
