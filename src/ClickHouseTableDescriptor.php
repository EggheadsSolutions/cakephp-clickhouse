<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Cache\Cache;
use LogicException;
use RuntimeException;

/**
 * @internal
 */
class ClickHouseTableDescriptor
{
    /** @var string Имя таблицы без префикса БД. */
    private string $_name;

    /** @var string Название профиля для чтения */
    private string $_readerProfile;

    /** @var string|null Название профиля для записи */
    private ?string $_writerProfile;

    /** @var TempTableClickHouse|null Экземпляр мок-таблицы. */
    private ?TempTableClickHouse $_mockTable;

    /** @var array<string, string>|null Схема таблицы ['имя поля' => 'тип'] */
    private ?array $_schema = null;

    /**
     * Конструктор.
     *
     * @param string $name
     * @param string $readerProfile
     * @param string|null $writerProfile
     * @param TempTableClickHouse|null $mockTable
     */
    public function __construct(string $name, string $readerProfile, ?string $writerProfile = null, ?TempTableClickHouse $mockTable = null)
    {
        $this->_name = $name;
        $this->_readerProfile = $readerProfile;
        $this->_writerProfile = $writerProfile;
        $this->_mockTable = $mockTable;
    }

    /**
     * Возвращает имя таблицы без префикса БД.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * Возвращает экземпляр профиля для чтения из таблицы.
     *
     * @return ClickHouse
     */
    public function getReader(): ClickHouse
    {
        return ClickHouse::getInstance($this->_readerProfile);
    }

    /**
     * Проверяет, задан-ли профиль для записи в таблицу.
     *
     * @return bool
     */
    public function hasWriter(): bool
    {
        return !is_null($this->_writerProfile);
    }

    /**
     * Возвращает экземпляр профиля для записи в таблицу.
     *
     * @return ClickHouse
     */
    public function getWriter(): ClickHouse
    {
        if (!$this->hasWriter()) {
            throw new LogicException("Для таблицы {$this->_name} не задан профиль для записи.");
        }

        return ClickHouse::getInstance($this->_writerProfile);
    }

    /**
     * Возвращает экземпляр мок-таблицы.
     *
     * @return TempTableClickHouse|null
     */
    public function getMockTable(): ?TempTableClickHouse
    {
        return $this->_mockTable;
    }

    /**
     * Получение схемы таблицы.
     *
     * @return array<string, string> ['имя поля' => 'тип']
     * @throws RuntimeException
     */
    public function getSchema(): array
    {
        if (!isset($this->_schema)) {
            $this->_schema =  Cache::remember("ClickHouse-schema#{$this->_readerProfile}.{$this->_name}", function () {
                $rows = $this->getReader()->select('DESCRIBE ' . $this->_name)->rows();

                if (empty($rows)) {
                    throw new RuntimeException('Не могу сформировать схему таблицы ' . $this->_name);
                }

                return array_column($rows, 'type', 'name');
            }, AbstractClickHouseTable::CACHE_PROFILE);
        }

        return $this->_schema;
    }
}
