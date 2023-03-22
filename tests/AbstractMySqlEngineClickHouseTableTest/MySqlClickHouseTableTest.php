<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractMySqlEngineClickHouseTableTest;

use Cake\Core\Configure;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\Tests\TestCase;

class MySqlClickHouseTableTest extends TestCase
{
    /** @inheritdoc */
    protected function _dropClickHouseTables(bool $dropTables = true): void
    {
        $writerClient = ClickHouse::getInstance()->getClient();
        $writerClient->write('DROP TABLE IF EXISTS default.fake_db_testMySqlEngine');
        $writerClient->write('DROP TABLE IF EXISTS default.testMySqlEngine');
    }

    /**
     * Тестируем подмену таблицы
     *
     * @return void
     */
    public function test(): void
    {
        // Создадим тестовую таблицу
        $testTableName = 'default.testMySqlEngine';
        $writer = ClickHouse::getInstance();
        $writer->getClient()->write(
            "CREATE TABLE {table}
                 (
                     id UInt32
                 )
                     ENGINE = MySQL('address:1', 'test_database', 'test_table_name', 'test_user', 'test_password')
                 COMMENT 'Тестовая таблица'",
            ['table' => $testTableName]
        );

        // При выключенном моке
        Configure::write('mockClickHouseDictionary', false);
        self::assertEquals($testTableName, TestMySqlEngineClickHouseTable::getInstance()->getTableName());

        // Чистим инстансы и кэш
        $this->_clearClickHouseTablesInfo();

        // При включенном моке
        Configure::write('mockClickHouseDictionary', true);

        $mockTable = TestMySqlEngineClickHouseTable::getInstance();
        self::assertEquals('default.fake_db_testMySqlEngine', $mockTable->getTableName()); // Имя таблицы подменилось
        $createStatement = $mockTable->select('SHOW CREATE TABLE {table}', ['table' => $mockTable->getTableName()])
            ->fetchOne('statement');

        self::assertStringContainsString('CREATE TABLE default.fake_db_testMySqlEngine', $createStatement);
        self::assertStringContainsString("MySQL('fake_host:3306', 'fake_db', 'test_table_name', 'fake_user', 'fake_password')", $createStatement);

        $schema = $mockTable->getSchema();
        self::assertEquals(['id' => 'UInt32'], $schema); // Проверили, что скопировал схему

        // Чистим инстансы и кэш, затем меняем структуру таблицы
        $this->_clearClickHouseTablesInfo();

        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => $testTableName]);
        $writer->getClient()->write(
            "CREATE TABLE {table}
                 (
                     id UInt32,
                     new UInt8
                 )
                 ENGINE = MySQL('address:1', 'test_database', 'test_table_name', 'test_user', 'test_password')
                 COMMENT 'Тестовая таблица'",
            ['table' => $testTableName]
        );

        $mockTable = TestMySqlEngineClickHouseTable::getInstance();
        $schema = $mockTable->getSchema();
        self::assertEquals([
            'id' => 'UInt32',
            'new' => 'UInt8',
        ], $schema); // Проверили, что таблица пересоздалась с новой схемой
    }
}
