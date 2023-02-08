<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\AbstractDictionaryClickHouseTableTest;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\StatementHelper;
use Eggheads\Mocks\PropertyAccess;

class DictionaryClickHouseTableTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::disable();
    }

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
        Configure::write('Datasources.default', [
            'database' => 'mock_db',
            'username' => 'mock_user',
            'password' => 'mock_password',
            'host' => 'mock_host',
        ]);
        $mockTable = TestDictClickHouseTable::getInstance();
        self::assertEquals('default.mock_db_testDict', $mockTable->getTableName()); // Имя таблицы подменилось
        $createStatement = $mockTable->select('SHOW CREATE TABLE {table}', ['table' => $mockTable->getTableName()])
            ->fetchOne('statement');
        $credentials = StatementHelper::extractCredentialsFromCreteTableStatement($createStatement);
        self::assertEquals([
            'host' => 'mock_host',
            'port' => '3306',
            'user' => 'mock_user',
            'password' => 'mock_password',
            'table' => 'test_table',
            'db' => 'mock_db',
            'update_field' => 'updated',
            'invalidate_query' => 'SELECT max(updated) FROM test_table',
        ], $credentials); // Сверяем, что параметры подключения к БД взялись из MySQL конфига
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
