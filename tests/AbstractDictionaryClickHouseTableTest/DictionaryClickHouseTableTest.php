<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractDictionaryClickHouseTableTest;

use Cake\Core\Configure;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\ClickHouseTableManager;
use Eggheads\CakephpClickHouse\Tests\TestCase;

class DictionaryClickHouseTableTest extends TestCase
{
    /** @inheritdoc */
    protected function _dropClickHouseTables(): void
    {
        $writerClient = ClickHouse::getInstance()->getClient();
        $writerClient->write('DROP DICTIONARY IF EXISTS default.testDict');
        $writerClient->write('DROP DICTIONARY IF EXISTS default.fake_db_testDict');
    }

    /**
     * Тестируем подмену словаря
     *
     * @return void
     */
    public function test(): void
    {
        // Создадим тестовый словарь
        $testDictName = 'default.testDict';
        $writer = ClickHouse::getInstance();
        $writer->getClient()->write(
            "CREATE DICTIONARY {table}
                    (id UInt64)
                    PRIMARY KEY id
                    SOURCE(MYSQL(HOST '1.2.3.4' PORT 3306 USER 'test_user' PASSWORD 'test_password' TABLE 'test_table' DB 'test_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM test_table'))
                    LIFETIME(MIN 0 MAX 300)
                    LAYOUT(HASHED(PREALLOCATE 1))",
            ['table' => $testDictName]
        );

        // При выключенном моке
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, false);
        self::assertEquals($testDictName, TestDictClickHouseTable::getInstance()->getTableName());

        // Чистим инстансы и кэш
        $this->_clearClickHouseTablesInfo();

        // При включенном моке
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, true);

        $mockTable = TestDictClickHouseTable::getInstance();
        self::assertEquals('default.fake_db_testDict', $mockTable->getTableName()); // Имя таблицы подменилось
        $createStatement = $mockTable->select('SHOW CREATE TABLE {table}', ['table' => $mockTable->getTableName()])
            ->fetchOne('statement');

        self::assertStringContainsString('CREATE DICTIONARY default.fake_db_testDict', $createStatement);
        self::assertStringContainsString("SOURCE(MYSQL(HOST 'fake_host' PORT 3306 USER 'fake_user' PASSWORD 'fake_password' TABLE 'test_table' DB 'fake_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM test_table'))", $createStatement);

        $schema = $mockTable->getSchema();
        self::assertEquals(['id' => 'UInt64'], $schema); // Проверили, что скопировал схему

        // Чистим инстансы и кэш, затем меняем структуру словаря
        $this->_clearClickHouseTablesInfo();

        $writer->getClient()->write('DROP DICTIONARY IF EXISTS {table}', ['table' => $testDictName]);
        $writer->getClient()->write(
            "CREATE DICTIONARY {table}
                    (id UInt8, new UInt8)
                    PRIMARY KEY id
                    SOURCE(MYSQL(HOST '1.2.3.4' PORT 3306 USER 'test_user' PASSWORD 'test_password' TABLE 'test_table' DB 'test_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM test_table'))
                    LIFETIME(MIN 0 MAX 300)
                    LAYOUT(HASHED(PREALLOCATE 1))",
            ['table' => $testDictName]
        );

        $mockTable = TestDictClickHouseTable::getInstance();
        $schema = $mockTable->getSchema();
        self::assertEquals([
            'id' => 'UInt64',
            'new' => 'UInt8',
        ], $schema); // Проверили, что словарь пересоздался с новой схемой
    }
}
