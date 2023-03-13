<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\ClickHouseTableManagerTest;

use Cake\Core\Configure;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouse;
use Eggheads\CakephpClickHouse\ClickHouseTableDescriptor;
use Eggheads\CakephpClickHouse\ClickHouseTableManager;
use Eggheads\CakephpClickHouse\TempTableClickHouse;
use Eggheads\CakephpClickHouse\Tests\TestCase;
use LogicException;

class ClickHouseTableManagerTest extends TestCase
{
    /** @inheritdoc */
    public function setUp(): void
    {
        parent::setUp();

        $writerClient = ClickHouse::getInstance('writer')->getClient();

        $writerClient->write('
            CREATE TABLE default.testSimple
            (
                `name` String
            )
            ENGINE = MergeTree() ORDER BY name
        ');

        $writerClient->write("
            CREATE TABLE default.testMysqlExternal
            (
                `mysqlId` UInt32
            )
            ENGINE = MySQL('address:1', 'test_database', 'test_external_table', 'test_user', 'test_password')
        ");

        $writerClient->write("
            CREATE DICTIONARY default.testMysqlDict
            (
                `dictId` UInt64
            )
            PRIMARY KEY dictId
            SOURCE(MYSQL(HOST 'address' PORT 1 USER 'test_user' PASSWORD 'test_password' TABLE 'test_dictionary_table' DB 'test_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM test_dictionary_table'))
            LIFETIME(MIN 0 MAX 300)
            LAYOUT(HASHED(PREALLOCATE 1))
        ");
    }

    /** @inheritdoc */
    protected function _dropClickHouseTables(): void
    {
        $writerClient = ClickHouse::getInstance('writer')->getClient();
        $writerClient->write('DROP TABLE IF EXISTS default.testSimple');
        $writerClient->write('DROP TABLE IF EXISTS default.testMysqlExternal');
        $writerClient->write('DROP TABLE IF EXISTS default.fake_db_testMysqlExternal');
        $writerClient->write('DROP DICTIONARY IF EXISTS default.testMysqlDict');
        $writerClient->write('DROP DICTIONARY IF EXISTS default.fake_db_testMysqlDict');
    }

    /**
     * Тестирование получения и очистки экземпляра-одиночки.
     *
     * @return void
     * @covers ClickHouseTableManager::getInstance
     * @covers ClickHouseTableManager::clearInstance
     */
    public function testGetAndClearInstance(): void
    {
        $instance = ClickHouseTableManager::getInstance();

        self::assertSame($instance, ClickHouseTableManager::getInstance());

        ClickHouseTableManager::clearInstance();

        self::assertNotSame($instance, ClickHouseTableManager::getInstance());
    }

    /**
     * Тестирование получения автоматически-создаваемого дескриптора таблицы и функционирования таблиц-дублёров.
     *
     * @param bool $useDoubler
     * @param AbstractClickHouseTable $table
     * @param ClickHouseTableDescriptor $expectedDescriptor
     * @return void
     * @dataProvider provideGetDescriptorAndAutoInitData
     * @covers ClickHouseTableManager::getDescriptor
     */
    public function testGetDescriptorAndAutoInit(bool $useDoubler, AbstractClickHouseTable $table, ClickHouseTableDescriptor $expectedDescriptor): void
    {
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, $useDoubler);
        $tableManager = ClickHouseTableManager::getInstance();

        $actualDescriptor = $tableManager->getDescriptor($table);

        self::assertEquals($expectedDescriptor, $actualDescriptor);
        self::assertSame($actualDescriptor, $tableManager->getDescriptor($table));
    }

    /**
     * Предоставляет данные для тестирования получения автоматически-создаваемого дескриптора таблицы и функционирования таблиц-дублёров.
     *
     * @return array<array{bool, AbstractClickHouseTable, ClickHouseTableDescriptor}>
     */
    public function provideGetDescriptorAndAutoInitData(): array
    {
        $readerProfile = 'default';
        $writerProfile = 'writer';

        return [
            'Обычная таблица с именем на основе класса по конвенции, есть профиль для записи' => [
                false,
                TestSimpleClickHouseTable::getInstance(),
                new ClickHouseTableDescriptor('testSimple', $readerProfile, $writerProfile),
            ],
            'Обычная таблица с объявленным в константе класса именем, нет профиля для записи' => [
                false,
                TestCustomClickHouseTable::getInstance(),
                new ClickHouseTableDescriptor('testSimple', $readerProfile),
            ],
            'Внешняя MySQL таблица, дублёры отключены, есть профиль для записи' => [
                false,
                TestMysqlExternalClickHouseTable::getInstance(),
                new ClickHouseTableDescriptor('testMysqlExternal', $readerProfile, $writerProfile),
            ],
            'Внешняя MySQL таблица, дублёры включены, есть профиль для записи' => [
                true,
                TestMysqlExternalClickHouseTable::getInstance(),
                new ClickHouseTableDescriptor('fake_db_testMysqlExternal', $readerProfile, $writerProfile),
            ],
            'Внешний MySQL словарь, дублёры отключены, нет профиля для записи' => [
                false,
                TestMysqlDictClickHouseTable::getInstance(),
                new ClickHouseTableDescriptor('testMysqlDict', $readerProfile),
            ],
            'Внешний MySQL словарь, дублёры включены, нет профиля для записи' => [
                true,
                TestMysqlDictClickHouseTable::getInstance(),
                new ClickHouseTableDescriptor('fake_db_testMysqlDict', $readerProfile),
            ],
        ];
    }

    /**
     * Тестирование ошибки когда не задана константа {@see AbstractClickHouseTable::TABLE} и имя класса таблицы не соответствует конвенции.
     *
     * @return void
     * @covers ClickHouseTableManager::getDescriptor
     */
    public function testGetDescriptorForBadTableObjects(): void
    {
        self::expectException(LogicException::class);

        $table = TestBadName::getInstance();
        ClickHouseTableManager::getInstance()->getDescriptor($table);
    }

    /**
     * Тестирование получения дескриптора, создания и пересоздания таблицы-дублёра для внешней MySQL таблицы при включённом использовании таблиц-дублёров.
     *
     * @return void
     * @covers ClickHouseTableManager::getDescriptor
     */
    public function testGetDescriptorAndEnabledDoublersUsingExternalMysqlTable(): void
    {
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, true);

        $readerProfile = 'default';
        $reader = ClickHouse::getInstance($readerProfile);
        $table = TestMysqlExternalClickHouseTable::getInstance();

        $expectedDescriptor = new ClickHouseTableDescriptor('fake_db_testMysqlExternal', $readerProfile, 'writer');

        // Проверяем дескриптор и впервые созданную таблицу-дублёр.
        self::assertEquals($expectedDescriptor, ClickHouseTableManager::getInstance()->getDescriptor($table));
        self::assertSame(<<<'EOF'
        CREATE TABLE default.fake_db_testMysqlExternal
        (
            `mysqlId` UInt32
        )
        ENGINE = MySQL('fake_host:3306', 'fake_db', 'test_external_table', 'fake_user', 'fake_password')
        EOF, $reader->getCreateTableStatement('default.fake_db_testMysqlExternal'));

        // Чистим состояние и меняем схему оригинальной таблицы.
        $this->_clearClickHouseTablesInfo();

        $readerClient = $reader->getClient();
        $readerClient->write('DROP TABLE IF EXISTS default.testMysqlExternal');
        $readerClient->write("
            CREATE TABLE default.testMysqlExternal
            (
                `mysqlId` UInt32,
                `new` UInt8
            )
            ENGINE = MySQL('address:1', 'test_database', 'test_new_external_table', 'test_user', 'test_password')
            COMMENT 'Добавленный комментарий'
        ");

        // Проверяем дескриптор и пересозданную из-за изменения схемы таблицу-дублёр.
        self::assertEquals($expectedDescriptor, ClickHouseTableManager::getInstance()->getDescriptor($table));
        self::assertSame(<<<'EOF'
        CREATE TABLE default.fake_db_testMysqlExternal
        (
            `mysqlId` UInt32,
            `new` UInt8
        )
        ENGINE = MySQL('fake_host:3306', 'fake_db', 'test_new_external_table', 'fake_user', 'fake_password')
        COMMENT 'Добавленный комментарий'
        EOF, $reader->getCreateTableStatement('default.fake_db_testMysqlExternal'));
    }

    /**
     * Тестирование получения дескриптора, создания и пересоздания таблицы-дублёра для внешнего MySQL словаря при включённом использовании таблиц-дублёров.
     *
     * @return void
     * @covers ClickHouseTableManager::getDescriptor
     */
    public function testGetDescriptorAndEnabledDoublersUsingExternalMysqlDict(): void
    {
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, true);

        $readerProfile = 'default';
        $reader = ClickHouse::getInstance($readerProfile);
        $table = TestMysqlDictClickHouseTable::getInstance();

        $expectedDescriptor = new ClickHouseTableDescriptor('fake_db_testMysqlDict', $readerProfile);

        // Проверяем дескриптор и впервые созданную таблицу-дублёр.
        self::assertEquals($expectedDescriptor, ClickHouseTableManager::getInstance()->getDescriptor($table));
        self::assertSame(<<<'EOF'
        CREATE DICTIONARY default.fake_db_testMysqlDict
        (
            `dictId` UInt64
        )
        PRIMARY KEY dictId
        SOURCE(MYSQL(HOST 'fake_host' PORT 3306 USER 'fake_user' PASSWORD 'fake_password' TABLE 'test_dictionary_table' DB 'fake_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT max(updated) FROM test_dictionary_table'))
        LIFETIME(MIN 0 MAX 300)
        LAYOUT(HASHED(PREALLOCATE 1))
        EOF, $reader->getCreateTableStatement('default.fake_db_testMysqlDict'));

        // Чистим состояние и меняем схему оригинальной таблицы.
        $this->_clearClickHouseTablesInfo();

        $readerClient = $reader->getClient();
        $readerClient->write('DROP DICTIONARY IF EXISTS default.testMysqlDict');
        $readerClient->write("
            CREATE DICTIONARY default.testMysqlDict
            (
                `dictId` UInt128,
                `new` Bool
            )
            PRIMARY KEY dictId
            SOURCE(MYSQL(HOST 'address' PORT 1 USER 'test_user' PASSWORD 'test_password' TABLE 'test_new_dictionary_table' DB 'test_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT min(updated) FROM test_new_dictionary_table'))
            LIFETIME(MIN 0 MAX 777)
            LAYOUT(HASHED(PREALLOCATE 1))
            COMMENT 'Добавленный комментарий'
        ");

        // Проверяем дескриптор и пересозданную из-за изменения схемы таблицу-дублёр.
        self::assertEquals($expectedDescriptor, ClickHouseTableManager::getInstance()->getDescriptor($table));
        self::assertSame(<<<'EOF'
        CREATE DICTIONARY default.fake_db_testMysqlDict
        (
            `dictId` UInt128,
            `new` Bool
        )
        PRIMARY KEY dictId
        SOURCE(MYSQL(HOST 'fake_host' PORT 3306 USER 'fake_user' PASSWORD 'fake_password' TABLE 'test_new_dictionary_table' DB 'fake_db' UPDATE_FIELD updated INVALIDATE_QUERY 'SELECT min(updated) FROM test_new_dictionary_table'))
        LIFETIME(MIN 0 MAX 777)
        LAYOUT(HASHED(PREALLOCATE 1))
        COMMENT 'Добавленный комментарий'
        EOF, $reader->getCreateTableStatement('default.fake_db_testMysqlDict'));
    }

    /**
     * Тестирование получения дескриптора и отсутствие таблицы-дублёра для внешней MySQL таблицы при отключенном использовании таблиц-дублёров.
     *
     * @return void
     * @covers ClickHouseTableManager::getDescriptor
     */
    public function testGetDescriptorAndDisabledDoublersUsingExternalMysqlTable(): void
    {
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, false);

        $readerProfile = 'default';
        $reader = ClickHouse::getInstance($readerProfile);
        $table = TestMysqlExternalClickHouseTable::getInstance();

        $expectedDescriptor = new ClickHouseTableDescriptor('testMysqlExternal', $readerProfile, 'writer');

        // Проверяем дескриптор и отсутствие таблицы-дублёра.
        self::assertEquals($expectedDescriptor, ClickHouseTableManager::getInstance()->getDescriptor($table));
        self::assertFalse($reader->isTableExist('default.fake_db_testMysqlExternal'));
    }

    /**
     * Тестирование получения дескриптора и отсутствие таблицы-дублёра для внешнего MySQL словаря при отключенном использовании таблиц-дублёров.
     *
     * @return void
     * @covers ClickHouseTableManager::getDescriptor
     */
    public function testGetDescriptorAndDisabledDoublersUsingExternalMysqlDict(): void
    {
        Configure::write(ClickHouseTableManager::USE_DOUBLERS_CONFIG_KEY, false);

        $readerProfile = 'default';
        $reader = ClickHouse::getInstance($readerProfile);
        $table = TestMysqlDictClickHouseTable::getInstance();

        $expectedDescriptor = new ClickHouseTableDescriptor('testMysqlDict', $readerProfile);

        // Проверяем дескриптор и отсутствие таблицы-дублёра.
        self::assertEquals($expectedDescriptor, ClickHouseTableManager::getInstance()->getDescriptor($table));
        self::assertFalse($reader->isTableExist('default.fake_db_testMysqlDict'));
    }

    /**
     * Тестирование назначения и получения назначенного дескриптора.
     *
     * @return void
     * @covers ClickHouseTableManager::getDescriptor
     * @covers ClickHouseTableManager::setDescriptor
     */
    public function testSetAndGetDescriptor(): void
    {
        $tableManager = ClickHouseTableManager::getInstance();
        $table = TestCustomClickHouseTable::getInstance();
        $descriptor = new ClickHouseTableDescriptor('someFakeName', 'default', 'ssdNode');

        $tableManager->setDescriptor($table, $descriptor);

        self::assertSame($descriptor, $tableManager->getDescriptor($table));
    }
}
