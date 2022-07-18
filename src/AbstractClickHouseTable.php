<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Cache\Cache;
use Cake\Chronos\ChronosInterface;
use Cake\I18n\FrozenDate;
use ClickHouseDB\Statement;
use LogicException;
use OutOfBoundsException;
use RuntimeException;

abstract class AbstractClickHouseTable implements ClickHouseTableInterface
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
            throw new LogicException('Не задана константа TABLE для класса ' . static::class);
        }
        if (empty(static::WRITER_CONFIG)) {
            throw new LogicException('Не задана константа WRITER_CONFIG для класса ' . static::class);
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

    /** @inheritdoc */
    public function checkField(string $inFieldName, string $defaultField): string
    {
        $schema = $this->getSchema();
        if (!empty($schema[$inFieldName])) {
            return $inFieldName;
        } else {
            if (empty($schema[$defaultField])) {
                throw new OutOfBoundsException("Поле $defaultField не найдено в таблице " . static::TABLE);
            }
            return $defaultField;
        }
    }

    /** @inheritdoc */
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
        return $this->_getWriter()->insertAssoc(static::TABLE, $recordOrRecords);
    }

    /**
     * Получаем экземпляр писалки
     *
     * @return ClickHouse
     */
    protected function _getWriter(): ClickHouse
    {
        return ClickHouse::getInstance(static::WRITER_CONFIG);
    }

    /** @inheritdoc */
    public function createTransaction(): ClickHouseTransaction
    {
        return new ClickHouseTransaction($this->_getWriter(), static::TABLE, array_keys($this->getSchema()));
    }

    /** @inheritdoc */
    public function truncate(): void
    {
        $this->_getWriter()->getClient()->write('TRUNCATE TABLE ' . static::TABLE);
    }

    /** @inheritdoc */
    public function deleteAll(string $conditions, array $bindings = []): void
    {
        $this->_getWriter()->getClient()->write('ALTER TABLE ' . static::TABLE . ' DELETE WHERE ' . $conditions, $bindings);
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
        $this->_getWriter()->getClient()->write('OPTIMIZE TABLE {me}', ['me' => static::TABLE]);
    }

    /** @inheritdoc */
    public function hasData(ChronosInterface $workDate, string $dateColumn = 'checkDate'): bool
    {
        return (bool)$this->getTotal($workDate, $dateColumn);
    }

    /** @inheritdoc */
    public function getTotal(ChronosInterface $workDate, string $dateColumn = 'checkDate'): int
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

    /** @inheritdoc */
    public function hasMutations(): bool
    {
        return $this->_getWriter()
                ->select(
                    'SELECT count() cnt FROM system.mutations WHERE database=:writerDB AND table=:table AND is_done=0',
                    [
                        'writerDB' => $this->_getWriter()->getClient()->settings()->getDatabase(),
                        'table' => static::TABLE,
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
            ['table' => static::TABLE, 'dateColumn' => $dateColumn]
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
        $clickHouse = $isReaderConfig ? $this->_getReader() : $this->_getWriter();
        return $clickHouse->getClient()->settings()->getDatabase() . '.' . static::TABLE;
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
