<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractDictionaryClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\Mocks\PropertyAccess;
use ReflectionException;

class DictionaryClickHouseTableTest extends TestCase
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
        $testDictName = 'testDict';
        $writer = ClickHouse::getInstance();
        $writer->getClient()->write('DROP DICTIONARY IF EXISTS {table}', ['table' => $testDictName]);
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
        Configure::write('mockClickHouseDictionary', false);
        self::assertEquals('default.testDict', TestDictClickHouseTable::getInstance()->getTableName());

        // Чистим инстансы
        PropertyAccess::setStatic(AbstractClickHouseTable::class, '_instances', []);

        // При включенном моке
        Configure::write('mockClickHouseDictionary', true);

        PropertyAccess::setStatic(ConnectionManager::class, '_config', []);
        ConnectionManager::setConfig('default', [
            'database' => 'mock_db',
            'port' => '3306',
            'username' => 'mock_user',
            'password' => 'mock_password',
            'host' => 'mock_host',
        ]);

        $mockTable = TestDictClickHouseTable::getInstance();
        self::assertEquals('default.mock_db_testDict', $mockTable->getTableName()); // Имя таблицы подменилось
        $createStatement = $mockTable->select('SHOW CREATE TABLE {table}', ['table' => $mockTable->getTableName()])
            ->fetchOne('statement');

        self::assertStringContainsString('CREATE DICTIONARY default.mock_db_testDict', $createStatement);
        self::assertStringContainsString("SOURCE(MYSQL(HOST 'mock_host' PORT 3306 USER 'mock_user' PASSWORD 'mock_password' TABLE 'test_table' DB 'mock_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM test_table'))", $createStatement);

        $schema = $mockTable->getSchema();
        self::assertEquals(['id' => 'UInt64'], $schema); // Проверили, что скопировал схему

        // Чистим инстансы и меняем структуру словаря
        PropertyAccess::setStatic(AbstractClickHouseTable::class, '_instances', []);
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

        // Чистим за собой
        $writer->getClient()->write('DROP DICTIONARY IF EXISTS {table}', ['table' => $testDictName]);
        $writer->getClient()->write('DROP DICTIONARY IF EXISTS {table}', ['table' => $mockTable->getTableName()]);
    }
}
