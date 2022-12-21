<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Cache\Cache;
use Cake\Chronos\ChronosInterface;
use Cake\I18n\FrozenDate;
use ClickHouseDB\Statement;
use LogicException;
use OutOfBoundsException;
use ReflectionClass;
use RuntimeException;

abstract class AbstractClickHouseTable implements ClickHouseTableInterface
{
    /**
     * Имя таблицы в БД, указывать только если имя класса не соответствует конвенции @see AbstractClickHouseTable::NAME_CONVENTION
     *
     * @var string
     * @internal
     */
    public const TABLE = '';

    /**
     * @var string Название конфигурации для чтения
     */
    public const READER_CONFIG = 'default';

    /**
     * @var string Название конфигурации для записи, заполнять только при необходимости записи в таблицу
     */
    public const WRITER_CONFIG = '';

    /**
     * Название профиля кеширования
     */
    public const CACHE_PROFILE = 'default';

    /**
     * Регулярка для поиска имени таблицы из названия класса согласно конвенции
     */
    private const NAME_CONVENTION = '/^(.+)ClickHouseTable$/';

    /**
     * Объект-одиночка
     *
     * @var array<string, static>
     */
    private static array $_instances = [];

    /**
     * Схема таблицы ['имя поля' => 'тип']
     *
     * @var null|array<string, string>
     */
    private ?array $_schema = null;

    /**
     * Имя таблицы
     *
     * @var string
     */
    private string $_tableName;

    /**
     * AbstractClickHouseTable constructor.
     *
     * @throws LogicException
     */
    protected function __construct()
    {
        $this->_buildTableName();
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
     * Формируем имя таблицы на основании имени класса
     *
     * @return void
     */
    private function _buildTableName(): void
    {
        if (!empty(static::TABLE)) {
            $this->_tableName = static::TABLE;
        } else {
            $reflect = new ReflectionClass($this);
            if (!preg_match(self::NAME_CONVENTION, $reflect->getShortName(), $matches)) {
                throw new LogicException('Не задана константа TABLE для класса ' . static::class . ' , и класс не соответствует конвенции ' . self::NAME_CONVENTION);
            }

            $this->_tableName = lcfirst($matches[1]);
        }
    }

    /**
     * Возвращает имя таблицы без префикса БД.
     *
     * @return string
     * @internal
     */
    protected function _getNamePart(): string
    {
        return $this->_tableName;
    }

    /** @inheritdoc */
    public function checkField(string $inFieldName, string $defaultField): string
    {
        $schema = $this->getSchema();
        if (!empty($schema[$inFieldName])) {
            return $inFieldName;
        } else {
            if (empty($schema[$defaultField])) {
                throw new OutOfBoundsException("Поле $defaultField не найдено в таблице " . $this->getTableName());
            }
            return $defaultField;
        }
    }

    /** @inheritdoc */
    public function getSchema(): array
    {
        if (empty($this->_schema)) {
            $this->_schema = Cache::remember('ClickHouse-schema#' . $this->getTableName(), function () {
                $rows = $this->select('DESCRIBE ' . $this->_getNamePart())->rows();
                return array_combine(array_column($rows, 'name'), array_column($rows, 'type'));
            }, self::CACHE_PROFILE);
            if (empty($this->_schema)) {
                throw new RuntimeException('Не могу сформировать схему таблицы ' . $this->_getNamePart());
            }
        }
        return $this->_schema;
    }

    /** @inheritdoc */
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
        return ClickHouse::getInstance(static::READER_CONFIG);
    }

    /** @inheritdoc */
    public function checkOrderDirection(string $inDirection): string
    {
        $inDirection = strtoupper($inDirection);
        return in_array($inDirection, ['ASC', 'DESC']) ? $inDirection : 'ASC';
    }

    /**
     * @inheritdoc
     * @phpstan-ignore-next-line
     */
    public function insert(array $recordOrRecords): Statement
    {
        return $this->_getWriter()->insertAssoc($this->_getNamePart(), $recordOrRecords);
    }

    /**
     * Получаем экземпляр писалки
     *
     * @return ClickHouse
     */
    protected function _getWriter(): ClickHouse
    {
        if (empty(static::WRITER_CONFIG)) {
            throw new LogicException('Не задана константа WRITER_CONFIG для класса ' . static::class);
        }

        return ClickHouse::getInstance(static::WRITER_CONFIG);
    }

    /** @inheritdoc */
    public function createTransaction(): ClickHouseTransaction
    {
        return new ClickHouseTransaction($this->_getWriter(), $this->_getNamePart(), array_keys($this->getSchema()));
    }

    /** @inheritdoc */
    public function truncate(): void
    {
        $this->_getWriter()->getClient()->write('TRUNCATE TABLE ' . $this->_getNamePart());
    }

    /** @inheritdoc */
    public function deleteAll(string $conditions, array $bindings = []): void
    {
        $this->_getWriter()->getClient()->write('ALTER TABLE ' . $this->_getNamePart() . ' DELETE WHERE ' . $conditions, $bindings);
    }

    /** @inheritdoc */
    public function deleteAllSync(string $conditions, array $bindings = []): void
    {
        $this->deleteAll($conditions, $bindings);
        $this->waitMutations();
    }

    /** @inheritdoc */
    public function optimize(): void
    {
        $this->_getWriter()->getClient()->write('OPTIMIZE TABLE {me}', ['me' => $this->_getNamePart()]);
    }

    /** @inheritdoc */
    public function hasData(ChronosInterface $workDate, string $dateColumn = 'checkDate'): bool
    {
        return (bool)$this->getTotal($workDate, $dateColumn);
    }

    /** @inheritdoc */
    public function getTotal(ChronosInterface $workDate, string $dateColumn = 'checkDate'): int
    {
        return (int)$this->select(
            'SELECT count() cnt FROM {me} WHERE {dateColumn}=:workDateString',
            [
                'dateColumn' => $dateColumn,
                'me' => $this->_getNamePart(),
                'workDateString' => $workDate->toDateString(),
            ]
        )->fetchOne('cnt');
    }

    /** @inheritdoc */
    public function hasMutations(): bool
    {
        return $this->_getWriter()
                ->select(
                    'SELECT count() cnt FROM system.mutations WHERE database=:writerDB AND table=:table AND is_done=0',
                    [
                        'writerDB' => $this->_getWriter()->getClient()->settings()->getDatabase(),
                        'table' => $this->_getNamePart(),
                    ]
                )->fetchOne('cnt') > 0;
    }

    /** @inheritdoc */
    public function waitMutations(): void
    {
        do {
            sleep(static::MUTATIONS_CHECK_INTERVAL);
        } while ($this->hasMutations());
    }

    /** @inheritdoc */
    public function getMaxDate(string $dateColumn = 'checkDate'): ?FrozenDate
    {
        $maxDate = $this->select(
            'SELECT if(toYear(max({dateColumn})) > 2000, max({dateColumn}), null) maxDate FROM {table}',
            ['table' => $this->_getNamePart(), 'dateColumn' => $dateColumn]
        )
            ->fetchOne('maxDate');
        return !empty($maxDate) ? FrozenDate::parse($maxDate) : null;
    }

    /**
     * @deprecated
     * @inheritdoc
     */
    public function getFullTableName(?bool $isReaderConfig = true): string
    {
        return $this->getTableName($isReaderConfig);
    }

    /** @inheritdoc */
    public function getTableName(?bool $isReaderConfig = true): string
    {
        $mockTableName = ClickHouseMockCollection::getTableName($this->getShortTableName());
        if ($mockTableName !== null) {
            return $mockTableName;
        }

        $clickHouse = $isReaderConfig ? $this->_getReader() : $this->_getWriter();
        return $clickHouse->getClient()->settings()->getDatabase() . '.' . $this->_getNamePart();
    }

    /**
     * Возвращает имя таблицы без префикса БД.
     *
     * @return string
     */
    public function getShortTableName(): string
    {
        return $this->_tableName;
    }

    /** @inheritdoc */
    public function getChunksIds(
        string $field,
        int    $chunksCount = 2,
        string $conditions = '',
        array  $bindings = []
    ): array {
        if ($chunksCount <= 1 || $chunksCount > ClickHouseTableInterface::MAX_CHUNKS) {
            throw new LogicException('Неверный параметр chunksCount');
        }
        $sql = '
            SELECT toString(quantile(:quantile)({field})) quantile
            FROM {table}
        ';
        if (!empty($conditions)) {
            $sql .= 'WHERE ' . $conditions;
        }
        $delta = 1 / $chunksCount;
        $result = [];
        for ($quantile = $delta; 1 - $quantile > 0.001; $quantile += $delta) {
            $result[] = (string)$this->select($sql, $bindings + [
                    'table' => $this->getTableName(),
                    'field' => $field,
                    'quantile' => $quantile,
                ])->fetchOne('quantile');
        }
        return $result;
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
