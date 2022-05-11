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
     * @param string $profile
     */
    public function __construct(string $name, array $typeMap, string $fillQuery, string $profile = 'default')
    {
        $date = FrozenTime::now();
        $this->_name = self::PREFIX . ucfirst($name) . $date->format('ymdHis') . '_' . $date->microsecond;
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
     * @return void
     */
    private function _fill(string $fillQuery): void
    {
        $this->_clickHouse->getClient()->write('INSERT INTO ' . $this->_name . ' ' . $fillQuery);
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
