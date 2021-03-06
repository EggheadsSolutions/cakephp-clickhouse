<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\ClickHouseTableInterface;
use Eggheads\Mocks\ConstantMocker;
use Eggheads\Mocks\MethodMocker;
use function PHPUnit\Framework\assertEquals;

class TestClickHouseTableTest extends TestCase
{
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

        ConstantMocker::restore();
        MethodMocker::restore($this->hasFailed());
    }

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

        $testTable->deleteAll("id = :id", ['id' => '1']);
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
        $testTable->deleteAllSync("id > :aboveId", ['aboveId' => '0']);
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
     * @param int $hasMutationIsTrueTimes
     * @return void
     * @dataProvider waitMutationsProvider
     * @covers       \Eggheads\CakephpClickHouse\AbstractClickHouseTable::waitMutations()
     * @uses         AbstractClickHouseTable::hasMutations()
     * @uses         ClickHouseTableInterface::MUTATIONS_CHECK_INTERVAL
     */
    public function testWaitMutations(int $hasMutationIsTrueTimes): void
    {
        $hasMutationCallTimes = ($hasMutationIsTrueTimes + 1);
        $hasMutationValues = array_pad([false], -$hasMutationCallTimes, true);
        MethodMocker::mock(AbstractClickHouseTable::class, 'hasMutations')
            ->expectCall($hasMutationCallTimes)
            ->willReturnValueList($hasMutationValues);

        ConstantMocker::mock(AbstractClickHouseTable::class, 'MUTATIONS_CHECK_INTERVAL', 0);

        TestClickHouseTable::getInstance()->waitMutations();
    }

    /**
     * Тестируем getChunksIds
     *
     * @return void
     * @see AbstractClickHouseTable::getChunksIds()
     */
    public function testGetChunksIds(): void
    {
        $testTable = TestClickHouseTable::getInstance();

        $svData = [];
        for ($iCounter = 0; $iCounter <= 100; $iCounter++) {
            if ($iCounter > 10 && $iCounter <= 30) {
                continue;
            }

            $svData[] = [
                'id' => (string)$iCounter,
                'url' => '',
                'data' => 0,
                'created' => '2020-08-04 09:00:00',
            ];
        }
        $testTable->insert($svData);

        self::assertEquals(['40', '60', '80'], $testTable->getChunksIds('toUInt8(id)', 4));
        self::assertEquals('60', $testTable->getChunksIds('toUInt8(id)')[0]);
        self::assertEquals('72.5', $testTable->getChunksIds('toUInt8(id)', 2, 'id >= :maxId', ['maxId' => '50'])[0]);
        self::assertCount(9, $testTable->getChunksIds('toUInt8(id)', 10));

        $this->expectExceptionMessage('Неверный параметр chunksCount');
        $testTable->getChunksIds('', 1);
    }

    /**
     * @return array<array{int}>
     */
    public function waitMutationsProvider(): array
    {
        return [
            'hasMutations * 1' => [1],
            'hasMutations * 4' => [4],
            'hasMutations * 0' => [0],
        ];
    }
}
