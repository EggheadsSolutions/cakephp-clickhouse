<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\ClickHouse;

class ClickHouseTest extends TestCase
{
    /**
     * @testdox Тест экземпляров в разной конфигурации
     *
     * @group clickHouse
     * @return void
     */
    public function testGetInstances(): void
    {
        $default = ClickHouse::getInstance();
        self::assertEquals(CLICKHOUSE_CONFIG['host'], $default->getClient()->getConnectHost());
        self::assertEquals(CLICKHOUSE_CONFIG['database'], $default->getClient()->settings()->getDatabase());

        self::assertTrue($default->getClient()->ping());

        $writer = ClickHouse::getInstance('writer');
        self::assertEquals(CLICKHOUSE_CONFIG['host'], $writer->getClient()->getConnectHost());
        self::assertEquals(CLICKHOUSE_CONFIG['database'], $writer->getClient()->settings()->getDatabase());
        self::assertTrue($writer->getClient()->ping());

        // второй экземпляр берётся из кеша
        $default2 = ClickHouse::getInstance();
        self::assertEquals($default->getClient()->getConnectHost(), $default2->getClient()->getConnectHost());
        self::assertEquals($default->getClient()->settings()->getDatabase(), $default2->getClient()->settings()->getDatabase());

        // второй экземпляр берётся из кеша
        $writer2 = ClickHouse::getInstance('writer');
        self::assertEquals($writer->getClient()->getConnectHost(), $writer2->getClient()->getConnectHost());
        self::assertEquals($writer->getClient()->settings()->getDatabase(), $writer2->getClient()->settings()->getDatabase());

        self::assertTrue($default2->getClient()->ping());
        self::assertTrue($writer2->getClient()->ping());
    }
}
