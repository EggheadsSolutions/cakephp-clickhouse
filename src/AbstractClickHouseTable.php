<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Chronos\ChronosInterface;
use Cake\I18n\FrozenDate;
use ClickHouseDB\Statement;
use LogicException;
use OutOfBoundsException;

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
    public const NAME_CONVENTION = '/(?<name>[^\\\\]+)ClickHouseTable$/';

    /** @var string Разделитель имени таблицы и базы данных */
    public const TABLE_NAME_DELIMITER = '.';

    /** @var string Квантиль отсутствующего значения */
    private const EMPTY_QUANTILE = 'nan';

    /**
     * Объект-одиночка
     *
     * @var array<string, static>
     */
    private static array $_instances = [];

    /**
     * AbstractClickHouseTable constructor.
     */
    protected function __construct()
    {
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
     * Возвращает имя таблицы без префикса БД.
     *
     * @return string
     * @internal
     */
    protected function _getNamePart(): string
    {
        return $this->_getDescriptor()->getName();
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
        return $this->_getDescriptor()->getSchema();
    }

    /** @inheritdoc */
    public function getCreateSQL(?bool $isReaderConfig = true): string
    {
        $descriptor = $this->_getDescriptor();
        $clickHouse = $isReaderConfig ? $descriptor->getReader() : $descriptor->getWriter();

        return $clickHouse->select('SHOW CREATE TABLE {me}', ['me' => $this->getTableName($isReaderConfig)])->fetchOne('statement');
    }

    /** @inheritdoc */
    public function select(string $query, array $bindings = [], ?bool $isReaderConfig = true): Statement
    {
        if ($isReaderConfig) {
            return $this->_getReader()->select($query, $bindings);
        } else {
            return $this->_getWriter()->select($query, $bindings);
        }
    }

    /** @inheritdoc */
    public function write(string $query, array $bindings = [], ?bool $isWriterConfig = true): Statement
    {
        if ($isWriterConfig) {
            return $this->_getWriter()->getClient()->write($query, $bindings);
        } else {
            return $this->_getReader()->getClient()->write($query, $bindings);
        }
    }

    /**
     * Получаем экземпляр читалки
     *
     * @return ClickHouse
     */
    protected function _getReader(): ClickHouse
    {
        return $this->_getDescriptor()->getReader();
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

        return $this->_getDescriptor()->getWriter();
    }

    /** @inheritdoc */
    public function createTransaction(): ClickHouseTransaction
    {
        return new ClickHouseTransaction($this->_getWriter(), $this->_getNamePart(), array_keys($this->getSchema()));
    }

    /** @inheritdoc */
    public function truncate(): void
    {
        $this->write('TRUNCATE TABLE ' . $this->_getNamePart());
    }

    /** @inheritdoc */
    public function deleteAll(string $conditions, array $bindings = []): void
    {
        $this->write('ALTER TABLE ' . $this->_getNamePart() . ' DELETE WHERE ' . $conditions, $bindings);
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
        $this->write('OPTIMIZE TABLE {me}', ['me' => $this->_getNamePart()]);
    }

    /** @inheritdoc */
    public function hasData(ChronosInterface $workDate, string $dateColumn = 'checkDate', string $conditionsString = ''): bool
    {
        return (bool)$this->getTotal($workDate, $dateColumn, $conditionsString);
    }

    /** @inheritdoc */
    public function getTotal(ChronosInterface $workDate, string $dateColumn = 'checkDate', string $conditionsString = ''): int
    {
        return (int)$this->select(
            'SELECT count() cnt FROM {me} WHERE {dateColumn}=:workDateString {conditionsString}',
            [
                'dateColumn' => $dateColumn,
                'me' => $this->getTableName(),
                'workDateString' => $workDate->toDateString(),
                'conditionsString' => $conditionsString,
            ]
        )->fetchOne('cnt');
    }

    /** @inheritdoc */
    public function getTotalInPeriod(
        ChronosInterface $workPeriodFrom,
        ChronosInterface $workPeriodTo,
        string           $dateColumn = 'checkDate',
        string           $conditionsString = ''
    ): int {
        return (int)$this->select("
            SELECT count(*) rowsCount FROM {thisTable} WHERE {dateColumn} BETWEEN :dateStart AND :dateEnd {conditionsString}
        ", [
            'thisTable' => $this->getTableName(),
            'dateColumn' => $dateColumn,
            'dateStart' => $workPeriodFrom->toDateString(),
            'dateEnd' => $workPeriodTo->toDateString(),
            'conditionsString' => $conditionsString,
        ])->fetchOne('rowsCount');
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
        $descriptor = $this->_getDescriptor();
        $clickHouse = $isReaderConfig ? $descriptor->getReader() : $descriptor->getWriter();

        return $clickHouse->getClient()->settings()->getDatabase() . self::TABLE_NAME_DELIMITER . $descriptor->getName();
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
            $qString = (string)$this->select($sql, $bindings + [
                    'table' => $this->getTableName(),
                    'field' => $field,
                    'quantile' => $quantile,
                ])->fetchOne('quantile');

            $result[] = $qString !== self::EMPTY_QUANTILE ? $qString : '';
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

    /**
     * Получение дескриптора этой таблицы.
     *
     * @return ClickHouseTableDescriptor
     */
    private function _getDescriptor(): ClickHouseTableDescriptor
    {
        return ClickHouseTableManager::getInstance()->getDescriptor($this);
    }
}
