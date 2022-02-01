<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;
use ClickHouseDB\Client;
use ClickHouseDB\Settings;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\ClickHouseTransaction;
use Eggheads\CakephpClickHouse\Exception\FieldNotFoundException;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;

class ClickHouseTransactionTest extends TestCase
{
    /** Название тестовой таблицы */
    private const TABLE = 'testTransaction';

    /** Название профиля подключения ClickHouse */
    private const CH_PROFILE = 'writer';

    /**
     * @testdox Проверим пакетную вставку
     *
     * @return void
     * @throws FieldNotFoundException
     */
    public function testCommit(): void
    {
        $clickhouse = ClickHouse::getInstance(self::CH_PROFILE);
        $transaction = new ClickHouseTransaction($clickhouse, self::TABLE, ['field2', 'field1']);
        $svData = [
            [
                'field1' => 'Строка 1',
                'field2' => 333,
            ],
            [
                'field0' => 'Не должен попасть',
                'field1' => 'Строка 2',
                'field2' => 123,
            ],
        ];

        foreach ($svData as $row) {
            $transaction->append($row);
        }

        self::assertTrue($transaction->hasData());
        self::assertEquals(2, $transaction->count());

        $transaction->commit();

        self::assertFalse($transaction->hasData());
        self::assertCount(0, $transaction);
        self::assertEquals(
            [
                [
                    'field1' => 'Строка 1',
                    'field2' => 333,
                ],
                [
                    'field1' => 'Строка 2',
                    'field2' => 123,
                ],
            ],
            $clickhouse->select('SELECT * FROM ' . self::TABLE)->rows()
        );
    }

    /**
     * @testdox Проверим пакетную вставку
     *
     * @return void
     * @throws FieldNotFoundException
     */
    public function testNotFoundException(): void
    {
        $this->expectException(FieldNotFoundException::class);
        $this->expectExceptionMessage('Не найдены поля');

        $clickhouse = ClickHouse::getInstance(self::CH_PROFILE);
        $transaction = new ClickHouseTransaction($clickhouse, self::TABLE, ['field2', 'field1']);
        $svData = [
            [
                'field11' => 'Не должен попасть 1',
            ],
            [
                'field0' => 'Не должен попасть 2',
            ],
        ];

        foreach ($svData as $row) {
            $transaction->append($row);
        }

        $transaction->commit();
    }

    /**
     * @testdox Повторный запрос при ошибке CH
     *
     * @return void
     */
    public function testRequestAttempts(): void
    {
        $this->expectExceptionMessage(ClickHouseTransaction::CH_ERROR_MESSAGE);

        /** @var MockObject|Client $stubClient */
        $stubClient = $this->createMock(Client::class);
        $stubClient->method('settings')
            ->willReturn($this->createMock(Settings::class));
        $stubClient->method('insertBatchFiles')
            ->willThrowException(new Exception(ClickHouseTransaction::CH_ERROR_MESSAGE));

        /** @var MockObject|ClickHouseTransaction $chTransaction */
        $chTransaction = $this->getMockBuilder(ClickHouseTransaction::class)
            ->setConstructorArgs([new ClickHouse($stubClient), self::TABLE, []])
            ->onlyMethods(['count'])
            ->getMock();

        $chTransaction->method('count')->willReturn(1);

        $chTransaction->commit();
    }

    /** @inerhitDoc */
    public function setUp(): void
    {
        parent::setUp();

        $writer = ClickHouse::getInstance(self::CH_PROFILE);
        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => self::TABLE]);
        $writer->getClient()->write(
            "CREATE TABLE {table}
            (
                field1  String,
                field2  UInt16
            ) ENGINE = MergeTree() ORDER BY field1",
            ['table' => self::TABLE]
        );

        Cache::disable();
    }
}
