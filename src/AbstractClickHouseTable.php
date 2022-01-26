<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Cache\Cache;
use Cake\I18n\I18nDateTimeInterface;
use ClickHouseDB\Statement;
use LogicException;
use OutOfBoundsException;
use RuntimeException;

/**
 * Экземпляр таблицы
 */
abstract class AbstractClickHouseTable
{
    /** @var string Имя таблицы в БД */
    public const TABLE = '';
    /** @var string Название конфигурации для чтения */
    public const READER_CONFIG = 'default';
    /** @var string Название конфигурации для записи */
    public const WRITER_CONFIG = '';
    /** Название профиля кеширования  */
    public const CACHE_PROFILE = 'default';
    /**
     * Объект-одиночка
     *
     * @var array<string, static>
     */
    private static $_instances = [];
    /** @var null|array<string, string> Схема таблицы ['имя поля' => 'тип'] */
    private ?array $_schema = null;

    /**
     * AbstractClickHouseTable constructor.
     *
     * @throws LogicException
     */
    protected function __construct()
    {
        if (empty(static::TABLE)) {
            throw new LogicException("Не задана константа TABLE для класса " . static::class);
        }
        if (empty(static::WRITER_CONFIG)) {
            throw new LogicException("Не задана константа WRITER_CONFIG для класса " . static::class);
        }
    }

    /**
     * Возвращает объект-одиночку
     *
     * @return static
     */
    public static function getInstance(): self
    {
        $className = static::class;
        if (empty(self::$_instances[$className])) {
            self::$_instances[$className] = new static(); //@phpstan-ignore-line
        }

        return self::$_instances[$className];
    }

    /**
     * Проверяем поле на существование в таблице
     *
     * @param string $inFieldName
     * @param string $defaultField
     * @return string
     * @throws OutOfBoundsException
     */
    public function checkField(string $inFieldName, string $defaultField): string
    {
        $schema = $this->getSchema();
        if (!empty($schema[$inFieldName])) {
            return $inFieldName;
        } else {
            if (empty($schema[$defaultField])) {
                throw new OutOfBoundsException('Поле $defaultField не найдено в таблице ' . static::TABLE);
            }
            return $defaultField;
        }
    }

    /**
     * Получаем схему таблицы
     *
     * @return array<string,string>
     * @throws RuntimeException
     */
    public function getSchema(): array
    {
        if (empty($this->_schema)) {
            $this->_schema = Cache::remember('ClickHouse-schema#' . static::TABLE, function () {
                $rows = $this->select('DESCRIBE ' . static::TABLE)->rows();
                return array_combine(array_column($rows, 'name'), array_column($rows, 'type'));
            }, self::CACHE_PROFILE);
            if (empty($this->_schema)) {
                throw new RuntimeException('Не могу сформировать схему таблицы ' . static::TABLE);
            }
        }
        return $this->_schema;
    }

    /**
     * Ищем данные из общей базы кластера
     *
     * @param string $query
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     *
     * @return Statement
     */
    public function select(string $query, array $bindings = []): Statement
    {
        return $this->_getReader()->select($query, $bindings);
    }

    /**
     * Получаем экземпляр читалки
     *
     * @return ClickHouse
     */
    protected function _getReader(): ClickHouse
    {
        return ClickHouse::getInstance(
            static::READER_CONFIG,
            $this->_getClickHouseConfig(static::READER_CONFIG)
        );
    }

    /**
     * Получение настроек для подключения ClickHouse
     *
     * @param string $profile
     * @return array
     *
     * @phpstan-ignore-next-line
     * @SuppressWarnings(PHPMD.MethodArgs)
     */
    abstract protected function _getClickHouseConfig(string $profile): array;

    /**
     * Проверяем варианты сортировки
     *
     * @param string $inDirection
     * @return string
     */
    public function checkOrderDirection(string $inDirection): string
    {
        $inDirection = strtoupper($inDirection);
        return in_array($inDirection, ['ASC', 'DESC']) ? $inDirection : 'ASC';
    }

    /**
     * Сохраняем одну/несколько записей в таблице
     *
     * @param array<string,string,string|int|float|string[]|int[]|float[]>|array<int,array<string,string,string|int|float|string[]|int[]|float[]>> $recordOrRecords
     * @return Statement
     * @phpstan-ignore-next-line
     */
    public function insert(array $recordOrRecords): Statement
    {
        return $this->_getWriter()->insertAssoc(static::TABLE, $recordOrRecords);
    }

    /**
     * Получаем экземпляр писалки
     *
     * @return ClickHouse
     */
    protected function _getWriter(): ClickHouse
    {
        return ClickHouse::getInstance(
            static::WRITER_CONFIG,
            $this->_getClickHouseConfig(static::READER_CONFIG)
        );
    }

    /**
     * Создаём транзакцию для сохранения очень большого объёма данных
     */
    public function createTransaction(): ClickHouseTransaction
    {
        return new ClickHouseTransaction($this->_getWriter(), static::TABLE, array_keys($this->getSchema()));
    }

    /**
     * Очищаем таблицу
     *
     * @return void
     */
    public function truncate(): void
    {
        $this->_getWriter()->getClient()->write('TRUNCATE TABLE ' . static::TABLE);
    }

    /**
     * Удаляем данные
     *
     * @param string $conditions
     * @void void
     */
    public function deleteAll(string $conditions): void
    {
        $this->_getWriter()->getClient()->write('ALTER TABLE ' . static::TABLE . ' DELETE WHERE ' . $conditions);
    }

    /**
     * Внеплановое слияние кусков данных для таблиц
     */
    public function optimize(): void
    {
        $this->_getWriter()->getClient()->write('OPTIMIZE TABLE {me}', ['me' => static::TABLE]);
    }

    /**
     * Есть ли данные на текущую дату
     *
     * @param I18nDateTimeInterface $workDate
     * @param string $dateColumn Поле с датой
     * @return bool
     */
    public function hasData(I18nDateTimeInterface $workDate, string $dateColumn = 'checkDate'): bool
    {
        return (bool)$this->getTotal($workDate, $dateColumn);
    }

    /**
     * Получаем общее кол-во записей на определённую дату
     *
     * @param I18nDateTimeInterface $workDate
     * @param string $dateColumn
     * @return int
     */
    public function getTotal(I18nDateTimeInterface $workDate, string $dateColumn = 'checkDate'): int
    {
        return (int)$this->_getWriter()
            ->select(
                'SELECT count() cnt FROM {me} WHERE {dateColumn}=:workDateString',
                [
                    'dateColumn' => $dateColumn,
                    'me' => static::TABLE,
                    'workDateString' => $workDate->toDateString(),
                ]
            )->fetchOne('cnt');
    }

    /**
     * Подчищаем инстанс, если объект уничтожили
     */
    public function __destruct()
    {
        self::$_instances = [];
    }

    /**
     * Защищаем от создания через клонирование
     */
    private function __clone()
    {
    }
}
