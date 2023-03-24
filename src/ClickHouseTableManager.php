<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use Eggheads\CakephpClickHouse\Entity\MySqlCredentialsItem;
use LogicException;

/**
 * @SuppressWarnings(PHPMD.MethodMix)
 */
class ClickHouseTableManager
{
    /** @var string Ключ параметра конфигурации активирующий использование таблиц-дублёров для внешних ClickHouse таблиц */
    public const USE_DOUBLERS_CONFIG_KEY = 'mockClickHouseDictionary';

    /** @var array<string, string> Карта имён таблиц по имени класса */
    private array $_nameByClass = [];

    /** @var array<string, ClickHouseTableDescriptor> Карта дескрипторов по имени таблицы */
    private array $_descriptorByName = [];

    /** @var self|null Экземпляр-одиночка этого класса */
    private static ?self $_instance = null; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

    /**
     * Защита от создания через new.
     */
    private function __construct()
    {
    }

    /**
     * Защита от клонирования.
     */
    private function __clone()
    {
    }

    /**
     * Получение экземпляра-одиночки этого класса.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        return self::$_instance ??= new self;
    }

    /**
     * Очистка экземпляра-одиночки этого класса.
     *
     * @return void
     */
    public static function clearInstance(): void
    {
        self::$_instance = null;
    }

    /**
     * Получение дескриптора таблицы.
     *
     * @param AbstractClickHouseTable $table
     * @return ClickHouseTableDescriptor
     */
    public function getDescriptor(AbstractClickHouseTable $table): ClickHouseTableDescriptor
    {
        $name = $this->_resolveName($table);

        return $this->_descriptorByName[$name] ??= $this->_createDescriptor($table, $name);
    }

    /**
     * Назначение дескриптора таблицы.
     *
     * @param AbstractClickHouseTable $table
     * @param ClickHouseTableDescriptor $descriptor
     * @return void
     */
    public function setDescriptor(AbstractClickHouseTable $table, ClickHouseTableDescriptor $descriptor): void
    {
        $this->_descriptorByName[$this->_resolveName($table)] = $descriptor;
    }

    /**
     * Вычисление основного имени для таблицы (без моков и дублёров).
     *
     * @param AbstractClickHouseTable $table
     * @return string
     */
    private function _resolveName(AbstractClickHouseTable $table): string
    {
        $class = get_class($table);

        if (!isset($this->_nameByClass[$class])) {
            if (!empty($table::TABLE)) {
                $this->_nameByClass[$class] = $table::TABLE;
            } elseif (preg_match(AbstractClickHouseTable::NAME_CONVENTION, $class, $matches) === 1) {
                $this->_nameByClass[$class] = lcfirst($matches['name']);
            } else {
                throw new LogicException('Не задана константа TABLE для класса ' . $class . ', и имя класса не соответствует выражению ' . AbstractClickHouseTable::NAME_CONVENTION);
            }
        }

        return $this->_nameByClass[$class];
    }

    /**
     * Создание дескриптора таблицы.
     *
     * @param AbstractClickHouseTable $table
     * @param string $name
     * @return ClickHouseTableDescriptor
     */
    private function _createDescriptor(AbstractClickHouseTable $table, string $name): ClickHouseTableDescriptor
    {
        $readerProfile = $table::READER_CONFIG;

        if ($table instanceof AbstractExternalSourceClickHouseTable
            && Configure::read(self::USE_DOUBLERS_CONFIG_KEY) && !(defined('TEST_MODE') && TEST_MODE)
        ) {
            $name = $this->_initDoubler($table, $name, $readerProfile);
        }

        return new ClickHouseTableDescriptor($name, $readerProfile, $table::WRITER_CONFIG ?: null);
    }

    /**
     * Инициализация таблицы-дублёра.
     *
     * @param AbstractExternalSourceClickHouseTable $table
     * @param string $originalName
     * @param string $readerProfile
     * @return string Имя инициализированной таблицы-дублёра.
     */
    private function _initDoubler(AbstractExternalSourceClickHouseTable $table, string $originalName, string $readerProfile): string
    {
        $reader = ClickHouse::getInstance($readerProfile);
        $readerClient = $reader->getClient();

        /** @var string|null $database Имя БД */
        $database = $readerClient->settings()->getDatabase();
        if (empty($database)) {
            throw new LogicException('Невозможно получить имя базы данных');
        }

        $credentialsItem = new MySqlCredentialsItem(ConnectionManager::getConfig('default'));

        $doublerName = Inflector::underscore($credentialsItem->database . '_') . $originalName;
        $doublerFullName = $database . AbstractClickHouseTable::TABLE_NAME_DELIMITER . $doublerName;

        $originalCreateStatement = $reader->getCreateTableStatement($database . AbstractClickHouseTable::TABLE_NAME_DELIMITER . $originalName);
        $properDoublerCreateStatement = $table->makeCreateDoublerStatement($originalCreateStatement, $doublerFullName, $credentialsItem);

        $existingDoublerCreateStatement = $reader->isTableExist($doublerFullName)
            ? $reader->getCreateTableStatement($doublerFullName)
            : null;

        if ($properDoublerCreateStatement !== $existingDoublerCreateStatement) {
            $readerClient->write($table->makeDropDoublerStatement($doublerFullName));
            $readerClient->write($properDoublerCreateStatement);
        }

        return $doublerName;
    }
}
