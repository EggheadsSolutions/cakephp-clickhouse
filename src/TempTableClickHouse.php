<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\I18n\FrozenTime;

/**
 * Класс для создания временной таблицы типа Memory
 *
 * @see https://clickhouse.com/docs/ru/engines/table-engines/special/memory
 */
class TempTableClickHouse
{
    private const PREFIX = 'temp';

    /**
     * Имя временной таблицы
     *
     * @var string
     */
    private string $_name;

    /**
     * Экземпляр класса
     *
     * @var ClickHouse
     */
    private ClickHouse $_clickHouse;

    /**
     * @param string $name
     * @param string[] $typeMap Массив типов полей в наборе
     * @param string $fillQuery SELECT запрос на наполнение сета, поля должны соблюдать порядок $typeMap
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     * @param string $profile
     */
    public function __construct(string $name, array $typeMap, string $fillQuery, array $bindings = [], string $profile = 'default')
    {
        $date = FrozenTime::now();
        $this->_name = self::PREFIX . ucfirst($name) . '_' . $date->format('ymdHis') . '_' . $date->microsecond;
        $this->_clickHouse = ClickHouse::getInstance($profile);

        $this->_create($typeMap);
        $this->_fill($fillQuery, $bindings);
    }

    /**
     * Удаляем временную таблицу
     */
    public function __destruct()
    {
        $this->_destroy();
    }

    /**
     * Получаем имя
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->_clickHouse->getClient()->settings()->getDatabase() . '.' . $this->_name;
    }

    /**
     * Создаём таблицу
     *
     * @param string [] $typeMap
     * @return void
     */
    private function _create(array $typeMap): void
    {
        $this->_destroy(); // на всякий случай

        $fieldsArr = [];
        foreach ($typeMap as $index => $fieldType) {
            $key = is_numeric($index) ? 'field' . $index : $index;
            $fieldsArr[] = $key . ' ' . $fieldType;
        }

        $query = 'CREATE TABLE IF NOT EXISTS ' . $this->_name . '
        (
            ' . implode(",\n", $fieldsArr) . '
        ) ENGINE = Memory()';

        $this->_clickHouse->getClient()->write($query);
    }

    /**
     * Наполняем сет
     *
     * @param string $fillQuery
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     * @return void
     */
    private function _fill(string $fillQuery, array $bindings = []): void
    {
        $this->_clickHouse->getClient()->write('INSERT INTO ' . $this->_name . ' ' . $fillQuery, $bindings);
    }

    /**
     * Удаляем таблицу
     *
     * @return void
     */
    private function _destroy(): void
    {
        $this->_clickHouse->getClient()->write('DROP TABLE IF EXISTS ' . $this->_name);
    }
}
