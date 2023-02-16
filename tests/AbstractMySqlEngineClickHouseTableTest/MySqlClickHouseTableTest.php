<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractMySqlEngineClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\StaticConfigTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\Mocks\PropertyAccess;
use ReflectionException;

class MySqlClickHouseTableTest extends TestCase
{
    /** @inheritdoc */
    public function setUp(): void
    {
        parent::setUp();
        Cache::disable();
    }

    /**
     * Тестируем подмену словаря
     *
     * @return void
     * @throws ReflectionException
     */
    public function test(): void
    {
        // Создадим тестовый словарь
        $testTableName = 'testMySqlEngine';
        $writer = ClickHouse::getInstance();
        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => $testTableName]);
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
        self::assertEquals('default.testMySqlEngine', TestMySqlEngineClickHouseTable::getInstance()->getTableName());

        // Чистим инстансы
        PropertyAccess::setStatic(AbstractClickHouseTable::class, '_instances', []);

        // При включенном моке
        Configure::write('mockClickHouseDictionary', true);

        PropertyAccess::setStatic(StaticConfigTrait::class, '_config', []);
        ConnectionManager::setConfig('default', [
            'database' => 'mock_db',
            'port' => '3306',
            'username' => 'mock_user',
            'password' => 'mock_password',
            'host' => 'mock_host',
        ]);

        $mockTable = TestMySqlEngineClickHouseTable::getInstance();
        self::assertEquals('default.mock_db_testMySqlEngine', $mockTable->getTableName()); // Имя таблицы подменилось
        $createStatement = $mockTable->select('SHOW CREATE TABLE {table}', ['table' => $mockTable->getTableName()])
            ->fetchOne('statement');

        self::assertStringContainsString('CREATE TABLE default.mock_db_testMySqlEngine', $createStatement);
        self::assertStringContainsString("MySQL('mock_host:3306', 'mock_db', 'test_table_name', 'mock_user', 'mock_password')", $createStatement);

        $schema = $mockTable->getSchema();
        self::assertEquals(['id' => 'UInt32'], $schema); // Проверили, что скопировал схему

        // Чистим инстансы и меняем структуру словаря
        PropertyAccess::setStatic(AbstractClickHouseTable::class, '_instances', []);
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

        // Чистим за собой
        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => $testTableName]);
        $writer->getClient()->write('DROP TABLE IF EXISTS {table}', ['table' => $mockTable->getTableName()]);
    }
}
