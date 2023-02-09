<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\StatementHelper;

class StatementHelperTest extends TestCase
{
    /**
     * @testdox Тестируем extractCredentialsFromCreteTableStatement
     *
     * @return void
     * @covers StatementHelper::extractCredentialsFromCreteTableStatement
     */
    public function testExtractCredentialsFromCreteTableStatement(): void
    {
        // Проверяем, в том числе, что добавление пробелов, и смена регистра не влияет на результат
        $statement = "CREATE DICTIONARY testDict
                (`id` UInt64)
                PRIMARY KEY id
                SOURCE(MYSQL(HOST '1.2.3.4' PORT 3000 user 'test_user' PASSWORD 'qwerty'  TABLE 'table_name' DB 'database_name' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM table_name'))
                LIFETIME(MIN 0 MAX 300)
                LAYOUT(HASHED(PREALLOCATE 1))";
        self::assertEquals([
            'host' => '1.2.3.4',
            'port' => '3000',
            'user' => 'test_user',
            'password' => 'qwerty',
            'table' => 'table_name',
            'db' => 'database_name',
            'update_field' => 'updated',
            'invalidate_query' => 'SELECT max(updated) FROM table_name',
        ], StatementHelper::extractCredentialsFromCreteTableStatement($statement));

        $statement = 'create table products (product_id UInt64, title String) Engine = Dictionary(products)';
        $this->expectExceptionMessage('Не является строкой создания таблицы словаря над MySQL');
        StatementHelper::extractCredentialsFromCreteTableStatement($statement);
    }

    /**
     * @testdox Тестируем replaceCredentialsInCreateTableStatement
     *
     * @return void
     * @covers StatementHelper::replaceCredentialsInCreateTableStatement
     */
    public function testReplaceCredentialsInCreateTableStatement(): void
    {
        $statement = "CREATE DICTIONARY testDict
                (`id` UInt64)
                PRIMARY KEY id
                SOURCE(MYSQL(HOST '1.2.3.4' PORT 3000 user 'test_user' PASSWORD 'qwerty'  TABLE 'table_name' DB 'database_name' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM table_name'))
                LIFETIME(MIN 0 MAX 300)
                LAYOUT(HASHED(PREALLOCATE 1))";
        $newStatement = StatementHelper::replaceCredentialsInCreateTableStatement($statement, [
            '1.2.3.4' => '5.6.7.8',
            'database_name' => 'new_database_name',
        ]);
        self::assertStringContainsString("DB 'new_database_name'", $newStatement);
        self::assertStringContainsString("HOST '5.6.7.8'", $newStatement);

        $this->expectExceptionMessage('Ошибка в изменении');
        StatementHelper::replaceCredentialsInCreateTableStatement($statement, [
            'not_found' => 'new',
        ]);
    }
}
