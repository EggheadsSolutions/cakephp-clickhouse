<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Eggheads\CakephpClickHouse\Exception\FieldNotFoundException;
use Exception;

abstract class AbstractClickHouseFixtureFactory
{
    /**
     * Массив данных для вставки во временную таблицу
     *
     * @var array<mixed>
     */
    protected array $_items = [];

    /**
     * Создание объекта фикстур
     *
     * Если передать $items=[] и $rowCount=0, то будет создана таблица без данных.
     * Такой вариант пригодится для тестирования наполнения cache таблиц
     *
     * @param array<mixed> $items Массив с данными для вставки в таблицу
     * @param int $rowCount Кол-во добавляемых дополнительных строк с дефолными значениями
     */
    public function __construct(array $items, int $rowCount = 0)
    {
        foreach ($items as $item) {
            $this->_items[] = $item + $this->_makeDefaultData();
        }

        for ($index = 0; $index < $rowCount; $index++) {
            $this->_items[] = $this->_makeDefaultData();
        }
    }

    /**
     * Создание данных элемента временной таблицы
     *
     * @return array<mixed>
     */
    abstract protected function _makeDefaultData(): array;

    /**
     * Подменяет временной таблицей с переданными фикстурами таблицу в оригинальном классе
     *
     * @return TempTableClickHouse
     * @throws FieldNotFoundException
     * @throws Exception
     */
    public function persist(): TempTableClickHouse
    {
        $tableManager = ClickHouseTableManager::getInstance();
        $table = $this->_getTable();
        $tableName = $tableManager->getDescriptor($table, false)->getName();
        $mockTable = new TempTableClickHouse($tableName, $table->getSchema());

        if (count($this->_items) > 0) {
            $transaction = $mockTable->createTransaction();

            foreach ($this->_items as $fixture) {
                $transaction->append($fixture);
            }

            $transaction->commit();
        }

        $tableManager->mock($table, $mockTable);

        return $mockTable;
    }

    /**
     * Получение инстанса класса ClickHouse таблицы
     *
     * @return AbstractClickHouseTable
     */
    abstract protected function _getTable(): AbstractClickHouseTable;
}
