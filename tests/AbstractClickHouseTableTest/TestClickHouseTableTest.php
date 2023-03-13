<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\I18n\FrozenDate;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\ClickHouseMockCollection;
use Eggheads\CakephpClickHouse\ClickHouseTableInterface;
use Eggheads\CakephpClickHouse\TempTableClickHouse;
use Eggheads\Mocks\ConstantMocker;
use Eggheads\Mocks\MethodMocker;
use Throwable;

class TestClickHouseTableTest extends TestCase
{
    /** Имя тестовой таблицы */
    private const TABLE_NAME = 'test';

    /** @inerhitDoc */
    public function setUp(): void
    {
        parent::setUp();

        $writer = ClickHouse::getInstance('writer');
        $writerTableName = TestClickHouseTable::getInstance()->getTableName(false);

        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => $writerTableName]);
        $writer->getClient()->write(
            'CREATE TABLE {table}
            (
                id      String,
                url     String,
                data    Decimal(10, 2),
                checkDate Date,
                created DateTime
            ) ENGINE = MergeTree() ORDER BY id',
            ['table' => $writerTableName]
        );

        Cache::disable();
        ClickHouseMockCollection::clear();
    }

    /** @inheritDoc */
    public function tearDown(): void
    {
        parent::tearDown();

        ConstantMocker::restore();
        MethodMocker::restore($this->hasFailed());
        ClickHouseMockCollection::clear();
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
            'checkDate' => 'Date',
            'created' => 'DateTime',
        ], $schema);

        $testTable->truncate();

        $svData = [
            [
                'id' => '1',
                'url' => 'bla-bla',
                'data' => 3.0,
                'checkDate' => '2020-08-04',
                'created' => '2020-08-04 09:00:00',
            ],
            [
                'id' => '2',
                'url' => 'ggggg',
                'data' => 5.0,
                'checkDate' => '2020-08-02',
                'created' => '2020-08-02 09:00:00',
            ],
        ];
        $testTable->insert($svData);

        self::assertEquals($svData, $this->_getAllRows($testTable));

        self::assertEquals(1, $testTable->getTotal(FrozenDate::parse('2020-08-02')));
        self::assertTrue($testTable->hasData(FrozenDate::parse('2020-08-02')));

        self::assertEquals(1, $testTable->getTotalInPeriod(
            FrozenDate::parse('2020-08-01'),
            FrozenDate::parse('2020-08-05'),
            'created',
            'AND data > 3'
        ));

        self::assertFalse($testTable->hasData(FrozenDate::parse('2016-08-02')));

        self::assertEquals('2020-08-04', $testTable->getMaxDate('created')->toDateString());

        $testTable->deleteAll("id = :id", ['id' => '1']);
        sleep(1);
        self::assertEquals([$svData[1]], $this->_getAllRows($testTable));

        $testTable->truncate();
        self::assertEmpty($this->_getAllRows($testTable));
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
                'checkDate' => '2020-08-04',
                'created' => '2020-08-04 09:00:00',
            ],
            [
                'id' => '2',
                'url' => 'ggggg',
                'data' => 5.0,
                'checkDate' => '2020-08-02',
                'created' => '2020-08-02 09:00:00',
            ],
        ];
        $testTable->insert($svData);

        self::assertNotEmpty($this->_getAllRows($testTable));
        $testTable->deleteAllSync("id > :aboveId", ['aboveId' => '0']);
        self::assertEmpty($this->_getAllRows($testTable));
    }

    /**
     * Тестируем getTableName
     *
     * @return void
     */
    public function testGetTableName(): void
    {
        $testTable = TestClickHouseTable::getInstance();
        $testTableName = 'default.' . self::TABLE_NAME;
        self::assertEquals($testTableName, $testTable->getTableName());

        $tempTable = TempTableClickHouse::createFromTable('clone', TestClickHouseTable::getInstance());
        ClickHouseMockCollection::add($testTable->getNamePart(false), $tempTable);
        self::assertEquals($tempTable->getName(), $testTable->getTableName());
        self::assertEquals($tempTable->getName(), $testTable->getTableName(false));

        ClickHouseMockCollection::clear();
        self::assertEquals($testTableName, $testTable->getTableName());
    }

    /**
     * @testdox Проверим создание, заполнение и мутации таблицы при использовании фикстур
     *
     * @return void
     * @throws Throwable
     */
    public function testFixtureFactory(): void
    {
        $testTable = TestClickHouseTable::getInstance();

        // Наполняем оригинальную таблицу данными для проверки, что мок не затрагивает её данные
        $originalRows = [
            [
                'id' => 'SomeOriginalId',
                'url' => 'SomeOriginalUrl',
                'data' => 17.3,
                'checkDate' => '2023-01-01',
                'created' => '2023-01-01 21:11:11',
            ],
        ];
        $testTable->insert($originalRows);

        // Инициализируем фикстуру и проверяем корректность её инициализации
        (new TestClickhouseFixtureFactory([['id' => 'id1', 'checkDate' => '2021-01-03',]], 2))->persist();

        $mockTable = ClickHouseMockCollection::getMockTable($testTable->getNamePart(false));

        self::assertNotNull($mockTable);
        self::assertSame($mockTable->getName(), $testTable->getTableName());
        self::assertSame($mockTable->getName(), $testTable->getTableName(false));

        $expectedRows = [
            [
                'id' => 'id1',
                'url' => 'String',
                'data' => 10.2,
                'checkDate' => '2021-01-03',
                'created' => '2020-03-01 23:12:12',
            ],
            [
                'id' => 'String',
                'url' => 'String',
                'data' => 10.2,
                'checkDate' => '2022-01-03',
                'created' => '2020-03-01 23:12:12',
            ],
            [
                'id' => 'String',
                'url' => 'String',
                'data' => 10.2,
                'checkDate' => '2022-01-03',
                'created' => '2020-03-01 23:12:12',
            ],
        ];
        self::assertSame($expectedRows, $this->_getAllRows($mockTable));
        self::assertSame($expectedRows, $this->_getAllRows($testTable));

        // Проверяем очистку таблицы
        $testTable->truncate();

        self::assertEmpty($this->_getAllRows($mockTable));
        self::assertEmpty($this->_getAllRows($testTable));

        // Проверяем вставку в таблицу
        $rowToInsert = [
            'id' => 'SomeInsertId',
            'url' => 'SomeInsertUrl',
            'data' => 15.5,
            'checkDate' => '2023-03-03',
            'created' => '2023-03-03 23:33:33',
        ];

        $testTable->insert($rowToInsert);

        self::assertSame([$rowToInsert], $this->_getAllRows($mockTable));
        self::assertSame([$rowToInsert], $this->_getAllRows($testTable));

        // Проверяем удаление из таблицы
        $testTable->deleteAll('TRUE');

        self::assertEmpty($this->_getAllRows($mockTable));
        self::assertEmpty($this->_getAllRows($testTable));

        // Проверяем транзакции
        $rowToAppend = [
            'id' => 'SomeAppendId',
            'url' => 'SomeAppendUrl',
            'data' => 75.1,
            'checkDate' => '2023-03-03',
            'created' => '2023-03-03 23:33:33',
        ];

        $transaction = $testTable->createTransaction();
        $transaction->append($rowToAppend);
        $transaction->commit();

        self::assertSame([$rowToAppend], $this->_getAllRows($mockTable));
        self::assertSame([$rowToAppend], $this->_getAllRows($testTable));

        // Проверяем, что мок не затронул данные оригинальной таблицы.
        ClickHouseMockCollection::clear();

        self::assertSame($originalRows, $this->_getAllRows($testTable));
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
                'checkDate' => '2020-08-04',
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

    /**
     * Получение всех строк таблицы.
     *
     * @param AbstractClickHouseTable|TempTableClickHouse $table
     * @return mixed[]
     */
    private function _getAllRows($table): array
    {
        if ($table instanceof TempTableClickHouse) {
            return $table->getClickHouse()->select("SELECT * FROM {table}", ['table' => $table->getName()])->rows();
        }

        return $table->select("SELECT * FROM {table}", ['table' => $table->getTableName()])->rows();
    }
}
