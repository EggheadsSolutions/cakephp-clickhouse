<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\I18n\FrozenTime;

/**
 * Класс для создания временной таблицы типа Set
 *
 * @see https://clickhouse.com/docs/ru/engines/table-engines/special/set/
 */
class QueryClickHouseSet
{
    private const PREFIX = 'tempSet';

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
     * @param string[] $typeMap Массив типов полей в наборе
     * @param string $fillQuery SELECT запрос на наполнение сета, поля должны соблюдать порядок $typeMap
     * @param string $profile
     */
    public function __construct(array $typeMap, string $fillQuery, string $profile = 'default')
    {
        $this->_name = self::PREFIX . FrozenTime::now()->format('ymdHis') . '_' . FrozenTime::now()->microsecond;
        $this->_clickHouse = ClickHouse::getInstance($profile);

        $this->_create($typeMap);
        $this->_fill($fillQuery);
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
        return $this->_name;
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
            $fieldsArr[] = 'field' . $index . ' ' . $fieldType;
        }

        $query = "CREATE TABLE IF NOT EXISTS " . $this->_name . "
        (
            " . implode(",\n", $fieldsArr) . "
        ) engine Set";

        $this->_clickHouse->getClient()->write($query);
    }

    /**
     * Наполняем сет
     *
     * @param string $fillQuery
     * @return void
     */
    private function _fill(string $fillQuery): void
    {
        $this->_clickHouse->getClient()->write("INSERT INTO " . $this->_name . " " . $fillQuery);
    }

    /**
     * Удаляем таблицу
     *
     * @return void
     */
    private function _destroy(): void
    {
        $this->_clickHouse->getClient()->write("DROP TABLE IF EXISTS " . $this->_name);
    }
}
