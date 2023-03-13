<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\ClickHouseTableDescriptor;
use LogicException;

class ClickHouseTableDescriptorTest extends TestCase
{
    /** @inheritdoc */
    public function setUp(): void
    {
        parent::setUp();

        ClickHouse::getInstance('writer')->getClient()->write('
            CREATE TABLE default.testDescriptor
            (
                `id` UInt64,
                `name` String,
                `price` Decimal(10, 2)
            )
            ENGINE = MergeTree() ORDER BY id
        ');
    }

    /** @inheritdoc */
    protected function _dropClickHouseTables(): void
    {
        ClickHouse::getInstance('writer')->getClient()->write('DROP TABLE IF EXISTS default.testDescriptor');
    }

    /**
     * Тестирование класса дескриптора таблицы.
     *
     * @return void
     * @covers ClickHouseTableDescriptor::__construct
     * @covers ClickHouseTableDescriptor::getName
     * @covers ClickHouseTableDescriptor::getReader
     * @covers ClickHouseTableDescriptor::getWriter
     * @covers ClickHouseTableDescriptor::getSchema
     */
    public function test(): void
    {
        $tableName = 'testDescriptor';
        $readerProfile = 'default';
        $writerProfile = 'writer';

        // Указаны все параметры.
        $descriptor = new ClickHouseTableDescriptor($tableName, $readerProfile, $writerProfile);

        self::assertSame($tableName, $descriptor->getName());
        self::assertSame(ClickHouse::getInstance($readerProfile), $descriptor->getReader());
        self::assertSame(ClickHouse::getInstance($writerProfile), $descriptor->getWriter());
        self::assertSame([
            'id' => 'UInt64',
            'name' => 'String',
            'price' => 'Decimal(10, 2)',
        ], $descriptor->getSchema());

        // Не указан профиль на запись.
        $descriptor = new ClickHouseTableDescriptor($tableName, $readerProfile, null);

        self::assertSame(ClickHouse::getInstance($readerProfile), $descriptor->getReader());

        self::expectExceptionObject(new LogicException('Для таблицы ' . $tableName . ' не задан профиль для записи.'));
        $descriptor->getWriter();
    }
}
