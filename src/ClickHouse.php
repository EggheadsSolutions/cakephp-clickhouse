<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Core\Configure;
use ClickHouseDB\Client;
use ClickHouseDB\Query\WhereInFile;
use ClickHouseDB\Query\WriteToFile;
use ClickHouseDB\Statement;
use DebugKit\DebugTimer;
use RuntimeException;

final class ClickHouse
{
    /** Время ожидания для web запросов */
    public const TIMEOUT = 150;

    /** Время ожидания для консольных скриптов */
    public const CLI_TIMEOUT = 1500;

    /** @var string[] Возвращаемое значение поля с типом Date, если его значение не указано в ClickHouse */
    public const EMPTY_DATE = ['1970-01-01', '0000-00-00'];

    /** @var string[] Возвращаемое значение поля с типом DateTime, если его значение не указано в ClickHouse */
    public const EMPTY_DATE_TIME = ['1970-01-01 00:00:00', '0000-00-00 00:00:00'];

    /** @var array<string, int|string|bool> Настройки по-умолчанию */
    private const DEFAULT_SETTINGS = [
        'timeout_before_checking_execution_speed' => false,
    ];

    /**
     * Объект-одиночка
     *
     * @var array<string, static>
     */
    private static array $_instance = [];
    /**
     * Инстанс клиента
     *
     * @var null|Client
     */
    private ?Client $_client;

    /**
     * Инициализация подключения к БД
     *
     * @param Client $clickHouse
     * @param null|array<string, int|string|bool> $settings Массив настроек ключ => значение
     */
    public function __construct(Client $clickHouse, ?array $settings = null)
    {
        $timeout = $this->_isCli() ? self::CLI_TIMEOUT : self::TIMEOUT;

        $clickHouse->setTimeout($timeout);
        $clickHouse->setConnectTimeOut($timeout);

        if (!empty($settings)) {
            foreach ($settings as $settingName => $settingValue) {
                $clickHouse->settings()->set($settingName, $settingValue);
            }
        }

        $this->_client = $clickHouse;
    }

    /**
     * Возвращает объект-одиночку
     *
     * @param string $profile
     * @return self
     */
    public static function getInstance(string $profile = 'default'): self
    {
        if (empty(self::$_instance[$profile])) {
            if ($profile === 'default') {
                $connectParams = Configure::read('clickHouseServer', []);
            } else {
                $writers = Configure::read('clickHouseWriters', []);
                if (empty($writers[$profile])) {
                    throw new RuntimeException("Profile $profile is not configured");
                }

                $connectParams = $writers[$profile];
            }

            $connectSettings = ($connectParams['settings'] ?? []) + Configure::read('clickHouseSettings', []) + self::DEFAULT_SETTINGS;

            $clickHouse = new Client($connectParams);
            $clickHouse->database($connectParams['database']);

            self::$_instance[$profile] = new self($clickHouse, $connectSettings);
        }

        return self::$_instance[$profile];
    }

    /**
     * Проброс select, дабы уменьшить кол-во вложенности
     *
     * @param string $sql
     * @param array<string,string|int|float|string[]|int[]|float[]> $bindings
     * @param WhereInFile|null $whereInFile
     * @param WriteToFile|null $writeToFile
     * @return Statement
     */
    public function select(
        string      $sql,
        array       $bindings = [],
        WhereInFile $whereInFile = null,
        WriteToFile $writeToFile = null
    ): Statement {
        $timerQuery = '';
        $isDebug = $this->_isDebug();
        if ($isDebug) {
            $timerQuery = "Clickhouse query: $sql";
            DebugTimer::start($timerQuery);
        }
        $result = $this->getClient()->select($sql, $bindings, $whereInFile, $writeToFile);
        if ($isDebug) {
            DebugTimer::stop($timerQuery);
        }
        return $result;
    }

    /**
     * Проверить существование таблицы
     *
     * @param string $tableName
     * @return bool
     */
    public function isTableExist(string $tableName): bool
    {
        return (bool)$this->select('EXISTS TABLE {dict}', [
            'dict' => $tableName,
        ])->fetchOne('result');
    }

    /**
     * Получить выражение для создания таблицы
     *
     * @param string $tableName
     * @return string
     */
    public function getCreateTableStatement(string $tableName): string
    {
        return $this->select('SHOW CREATE TABLE {dict}', [
            'dict' => $tableName,
        ])->fetchOne('statement');
    }


    /**
     * Включён ли режим отладки
     *
     * @return bool
     */
    private function _isDebug(): bool
    {
        return Configure::read('debug', true);
    }

    /**
     * Проверка, что запуск в консольной среде
     *
     * @return bool
     */
    private function _isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Получаем экземпляр клиента ClickHouse
     *
     * @return Client
     * @throws RuntimeException
     */
    public function getClient(): Client
    {
        if (!$this->_client) {
            throw new RuntimeException('Нет подключения к БД');
        }

        return $this->_client;
    }

    /**
     * Проброс на сохранение ассоциативного массива
     *
     * @param string $tableName
     * @param array<string,string|int|float|string[]|int[]|float[]|null>|array<int,array<string,string|int|float|string[]|int[]|float[]|null>> $values
     * @return Statement
     */
    public function insertAssoc(string $tableName, array $values): Statement
    {
        $timerQuery = '';
        $isDebug = $this->_isDebug();
        if ($isDebug) {
            $timerQuery = "Clickhouse insert: $tableName";
            DebugTimer::start($timerQuery);
        }
        $result = $this->getClient()->insertAssocBulk($tableName, $values);
        if ($isDebug) {
            DebugTimer::stop($timerQuery);
        }
        return $result;
    }

    /**
     * Подчищаем, если объект уничтожили
     */
    public function __destruct()
    {
        self::$_instance = [];
    }

    /**
     * Защищаем от создания через клонирование
     */
    private function __clone()
    {
    }
}
