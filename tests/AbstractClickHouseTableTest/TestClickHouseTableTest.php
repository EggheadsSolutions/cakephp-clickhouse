<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\Mocks\MethodMocker;
use function PHPUnit\Framework\assertEquals;

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
            'id' => 'String',
            'url' => 'String',
            'data' => 'Decimal(10, 2)',
            'created' => 'DateTime',
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

        assertEquals('2020-08-04', $testTable->getMaxDate('created')->toDateString());

        $testTable->deleteAll("id = '1'");
        sleep(1);
        self::assertEquals([$svData[1]], $testTable->select($selectAllQuery)->rows());

        $testTable->truncate();
        self::assertEmpty($testTable->select($selectAllQuery)->rows());
    }

    /**
     * Тестируем deleteAllSync
     *
     * @return void
     * @see AbstractClickHouseTable::deleteAllSync()
     */
    public function testDeleteAllSync(): void
    {
        $testTable = TestClickHouseTable::getInstance();

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

        self::assertNotEmpty($testTable->select($selectAllQuery)->rows());
        $testTable->deleteAllSync("id > '0'");
        self::assertEmpty($testTable->select($selectAllQuery)->rows());
    }

    /**
     * Тестируем getTableName
     *
     * @return void
     */
    public function testGetTableName(): void
    {
        self::assertEquals('default.testTable', TestClickHouseTable::getInstance()->getTableName());
    }

    /**
     * Тестируем `waitMutations`.
     *
     * @param int $hasMutationTimes
     * @param positive-int|null $intervalParam
     * @param positive-int $expectedTimeSpent
     * @return void
     * @dataProvider waitMutationsProvider
     * @covers \Eggheads\CakephpClickHouse\AbstractClickHouseTable::waitMutations()
     * @uses AbstractClickHouseTable::hasMutations()
     */
    public function testWaitMutations(int $hasMutationTimes, ?int $intervalParam, int $expectedTimeSpent): void
    {
        $hasMutationValues = array_merge(array_fill(0, $hasMutationTimes, true), [false]);
        MethodMocker::mock(AbstractClickHouseTable::class, 'hasMutations')
            ->expectCall($hasMutationTimes + 1)
            ->willReturnValueList($hasMutationValues);

        $testTable = TestClickHouseTable::getInstance();

        $startedAt = time();

        $testTable->waitMutations($intervalParam);

        $timeSpent = (time() - $startedAt);
        self::assertEquals($expectedTimeSpent, $timeSpent);
    }

    /** @inerhitDoc */
    public function setUp(): void
    {
        parent::setUp();

        $writer = ClickHouse::getInstance('writer');
        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => TestClickHouseTable::TABLE]);
        $writer->getClient()->write(
            'CREATE TABLE {table}
            (
                id      String,
                url     String,
                data    Decimal(10, 2),
                created DateTime
            ) ENGINE = MergeTree() ORDER BY id',
            ['table' => TestClickHouseTable::TABLE]
        );

        Cache::disable();
    }

    /** @inheritDoc */
    public function tearDown(): void
    {
        parent::tearDown();

        MethodMocker::restore($this->hasFailed());
    }

    /**
     * @return array<array{int, int|null, int}>
     */
    public function waitMutationsProvider(): array
    {
        return [
            'Дефолтный `$interval`' => [1, null, 4],
            'Кастомный `$interval`' => [4, 1, 5],
            'Отсутствие мутаций' => [0, 3, 3],
        ];
    }
}
