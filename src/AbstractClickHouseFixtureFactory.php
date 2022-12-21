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
     * Добавление данных для вставки во временную таблицу
     *
     * @param array<mixed> $items
     * @param int $rowCount - Мин кол-во добавляемых в таблицу строк
     */
    public function __construct(array $items, int $rowCount = 10)
    {
        $count = max($rowCount, count($items));
        for ($index = 0; $index < $count; $index++) {
            $item = $items[$index] ?? [];
            $this->_items[] = $item + $this->_getDefaultData();
        }
    }

    /**
     * Данные для сохранения во временную таблицу
     *
     * @return array<mixed>
     */
    abstract protected function _getDefaultData(): array;

    /**
     * Подменяет временной таблицей с переданными фикстурами таблицу в оригинальном классе
     *
     * @return TempTableClickHouse
     * @throws FieldNotFoundException
     * @throws Exception
     */
    public function persist(): TempTableClickHouse
    {
        $table = $this->_getTable();
        $tempTable = new TempTableClickHouse($table->getShortTableName(), $table->getSchema());
        $transaction = $tempTable->createTransaction();

        foreach ($this->_items as $fixture) {
            $transaction->append($fixture);
        }

        $transaction->commit();

        ClickHouseMockCollection::add($table->getShortTableName(), $tempTable);

        return $tempTable;
    }

    /**
     * Название класса для ClickHouse таблицы
     *
     * @return AbstractClickHouseTable
     */
    abstract protected function _getTable(): AbstractClickHouseTable;
}
