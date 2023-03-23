<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests;

use Cake\Cache\Cache;
use Cake\TestSuite\TestCase as BaseTestCase;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;
use Eggheads\CakephpClickHouse\ClickHouseTableManager;

class TestCase extends BaseTestCase
{
    /** @inheritdoc */
    public function setUp(): void
    {
        parent::setUp();

        $this->_clearClickHouseTablesInfo();
        $this->_dropClickHouseTables();
    }

    /** @inheritdoc */
    public function tearDown(): void
    {
        $this->_clearClickHouseTablesInfo();
        $this->_dropClickHouseTables();

        parent::tearDown();
    }

    /**
     * Очистка состояния менеджера таблиц и кэша.
     *
     * @return void
     */
    protected function _clearClickHouseTablesInfo(): void
    {
        ClickHouseTableManager::clearInstance();
        Cache::clear(AbstractClickHouseTable::CACHE_PROFILE);
    }

    /**
     * Удаление таблиц созданных во время выполнения тестов.
     *
     * @return void
     */
    protected function _dropClickHouseTables(): void
    {
    }
}
