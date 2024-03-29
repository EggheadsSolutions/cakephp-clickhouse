<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Core\Configure;
use Cake\I18n\FrozenTime;

/**
 * Класс для создания временной таблицы типа Memory
 *
 * В Конфигурации проекта необходимо прописать настройки temp профиля
 * Пример:
 * 'temp' => [
 *    'host' => 'example.com',
 *    'port' => '80',
 *    'username' => 'default',
 *    'password' => '',
 *    'database' => 'default',
 * ],
 *
 * так же можно указать префикс для создаваемых таблиц.
 * Пример: 'tempTableClickHousePrefix': 'test'
 *
 * @see https://clickhouse.com/docs/ru/engines/table-engines/special/memory
 * @SuppressWarnings(PHPMD.MethodMix)
 */
class TempTableClickHouse
{
    /**
     * Префикс названия создаваемой таблицы по-умолчанию
     */
    private const DEFAULT_PREFIX = 'temp';

    /** @var string Название конфигурации с префиксом временной таблицы */
    private const TEMP_TABLE_PREFIX_SETTING = 'tempTableClickHousePrefix';

    /** @var string Профиль по-умолчанию */
    public const DEFAULT_PROFILE = 'temp';

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
     * Структура таблицы
     *
     * @var array<string, string>
     */
    private array $_fieldsSchema = [];

    /**
     * Фабрика создания временной таблицы на основе существующей
     *
     * @param string $name
     * @param ClickHouseTableInterface $sourceTable
     * @param string $fillQuery
     * @param array<string, mixed> $bindings
     * @param string $profile
     * @return self
     */
    public static function createFromTable(
        string                   $name,
        ClickHouseTableInterface $sourceTable,
        string                   $fillQuery = '',
        array                    $bindings = [],
        string                   $profile = self::DEFAULT_PROFILE
    ): self {
        return new self(
            $name,
            $sourceTable->getSchema(),
            $fillQuery,
            $bindings,
            $profile
        );
    }

    /**
     * @param string $name
     * @param string[]|array<string, string> $typeMap Массив типов полей в наборе
     * @param string $fillQuery SELECT запрос на наполнение сета, поля должны соблюдать порядок $typeMap
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     * @param string $profile
     *
     * @example new TempTableClickHouse('tableName', ['id' => 'int'], 'SELECT :id', ['id' => 123], 'writer');
     */
    public function __construct(string $name, array $typeMap, string $fillQuery = '', array $bindings = [], string $profile = self::DEFAULT_PROFILE)
    {
        $date = FrozenTime::now();

        $prefix = Configure::read(self::TEMP_TABLE_PREFIX_SETTING) ?? self::DEFAULT_PREFIX;
        $this->_name = $prefix . ucfirst($name) . '_' . $date->format('ymdHis') . '_' . uniqid();
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
     * Получаем полное имя таблицы с префиксом БД
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->_clickHouse->getClient()->settings()->getDatabase() . '.' . $this->_name;
    }

    /**
     * Получение части имени таблицы без префикса БД.
     *
     * @return string
     */
    public function getNamePart(): string
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
            $key = is_numeric($index) ? 'field' . $index : $index;
            $fieldsArr[] = sprintf('`%s` %s', $key, $fieldType);
            $this->_fieldsSchema[$key] = $fieldType;
        }

        $query = 'CREATE TABLE IF NOT EXISTS ' . $this->_name . '
        (
            ' . implode(",\n", $fieldsArr) . '
        ) ENGINE = Memory()';

        $this->_clickHouse->getClient()->write($query);
    }

    /**
     * Наполняем таблицу
     *
     * @param string $fillQuery
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     * @return void
     */
    private function _fill(string $fillQuery, array $bindings = []): void
    {
        if ($fillQuery) {
            $this->_clickHouse->getClient()->write('INSERT INTO ' . $this->_name . ' ' . $fillQuery, $bindings);
        }
    }

    /**
     * Создаём транзакцию для сохранения очень большого объёма данных
     *
     * @return ClickHouseTransaction
     */
    public function createTransaction(): ClickHouseTransaction
    {
        return new ClickHouseTransaction($this->_clickHouse, $this->_name, array_keys($this->_fieldsSchema));
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
