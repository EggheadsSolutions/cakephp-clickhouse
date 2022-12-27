<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Chronos\ChronosInterface;
use Cake\I18n\FrozenDate;
use ClickHouseDB\Statement;
use OutOfBoundsException;
use RuntimeException;

/**
 * Экземпляр таблицы
 */
interface ClickHouseTableInterface
{
    /** @var positive-int Количество секунд - интервал ожидания между проверками завершения мутаций. */
    public const MUTATIONS_CHECK_INTERVAL = 5;

    /** @var int Максимальное кол-во частей, на которых можно произвести разбиение таблицы */
    public const MAX_CHUNKS = 10;

    /**
     * Проверяем поле на существование в таблице
     *
     * @param string $inFieldName
     * @param string $defaultField
     * @return string
     * @throws OutOfBoundsException
     */
    public function checkField(string $inFieldName, string $defaultField): string;

    /**
     * Получаем схему таблицы
     *
     * @return array<string,string>
     * @throws RuntimeException
     */
    public function getSchema(): array;

    /**
     * Ищем данные из общей базы кластера
     *
     * @param string $query
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     *
     * @return Statement
     */
    public function select(string $query, array $bindings = []): Statement;

    /**
     * Проверяем варианты сортировки
     *
     * @param string $inDirection
     * @return string
     */
    public function checkOrderDirection(string $inDirection): string;

    /**
     * Сохраняем одну/несколько записей в таблице
     *
     * @param array<string,string,string|int|float|string[]|int[]|float[]>|array<int,array<string,string,string|int|float|string[]|int[]|float[]>> $recordOrRecords
     * @return Statement
     * @phpstan-ignore-next-line
     */
    public function insert(array $recordOrRecords): Statement;

    /**
     * Создаём транзакцию для сохранения очень большого объёма данных
     */
    public function createTransaction(): ClickHouseTransaction;

    /**
     * Очищаем таблицу
     *
     * @return void
     */
    public function truncate(): void;

    /**
     * Удаляем данные
     *
     * @param string $conditions
     * @param mixed[] $bindings
     * @return void
     */
    public function deleteAll(string $conditions, array $bindings = []): void;

    /**
     * Удаляем данные и удостоверяемся о завершении всех мутаций
     *
     * @param string $conditions
     * @param mixed[] $bindings
     * @return void
     */
    public function deleteAllSync(string $conditions, array $bindings = []): void;

    /**
     * Внеплановое слияние кусков данных для таблиц
     */
    public function optimize(): void;

    /**
     * Есть ли данные на текущую дату
     *
     * @param ChronosInterface $workDate
     * @param string $dateColumn Поле с датой
     * @return bool
     */
    public function hasData(ChronosInterface $workDate, string $dateColumn = 'checkDate'): bool;

    /**
     * Получаем общее кол-во записей на определённую дату
     *
     * @param ChronosInterface $workDate
     * @param string $dateColumn
     * @param string $conditionsString
     * @return int
     */
    public function getTotal(ChronosInterface $workDate, string $dateColumn = 'checkDate', string $conditionsString = ''): int;

    /**
     * Получаем общее кол-во записей в периоде.
     *
     * @param ChronosInterface $workPeriodFrom
     * @param ChronosInterface $workPeriodTo
     * @param string $dateColumn
     * @param string $conditionsString
     * @return int
     */
    public function getTotalInPeriod(ChronosInterface $workPeriodFrom, ChronosInterface $workPeriodTo, string $dateColumn = 'checkDate', string $conditionsString = ''): int;

    /**
     * Проверяем, есть ли у таблицы мутации на текущий момент
     *
     * @return bool
     */
    public function hasMutations(): bool;

    /**
     * Ждём завершения мутаций выполняя проверку каждые `static::MUTATIONS_CHECK_INTERVAL` секунд.
     *
     * @return void
     */
    public function waitMutations(): void;

    /**
     * Получаю максимальную дату наличия записей
     *
     * @param string $dateColumn
     * @return FrozenDate|null
     */
    public function getMaxDate(string $dateColumn = 'checkDate'): ?FrozenDate;

    /**
     * @param bool $isReaderConfig Брать ли имя БД READER_CONFIG (иначе из WRITER_CONFIG)
     * @return string
     * @deprecated
     * Получить полное имя таблицы
     *
     */
    public function getFullTableName(?bool $isReaderConfig = true): string;

    /**
     * Получить полное имя таблицы
     *
     * @param bool $isReaderConfig Брать ли имя БД READER_CONFIG (иначе из WRITER_CONFIG)
     *
     * @return string
     */
    public function getTableName(?bool $isReaderConfig = true): string;

    /**
     * Возвращает идентификаторы разбиения таблицы на $partsCount примерно равных по размеру частей
     *
     * @param string $field По какому полю производится разбиение (должно быть числовым или приведенным к нему)
     * @param int $chunksCount На сколько частей производится разбиение (по-умолчанию на 2)
     * @param string $conditions Условие выборки
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     * @return string[] Массив из $parts-1 идентификаторов, по которому выполнилось разбиение
     */
    public function getChunksIds(
        string $field,
        int    $chunksCount = 2,
        string $conditions = '',
        array $bindings = []
    ): array;
}
