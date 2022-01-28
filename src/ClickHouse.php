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
    public const TIMEOUT = 150;

    /** @var string[] Возвращаемое значение поля с типом Date если его значение не указано в ClickHouse */
    public const EMPTY_DATE = ['1970-01-01', '0000-00-00'];

    /** @var string[] Возвращаемое значение поля с типом DateTime если его значение не указано в ClickHouse */
    public const EMPTY_DATE_TIME = ['1970-01-01 00:00:00', '0000-00-00 00:00:00'];
    /**
     * Объект-одиночка
     *
     * @var array<string, static>
     */
    private static array $_instance = [];
    /**
     * @var null|Client
     */
    private ?Client $_client;

    /**
     * Инициализация подключения к БД
     *
     * @param Client $clickHouse
     */
    public function __construct(Client $clickHouse)
    {
        if ($this->_isCli()) {
            $timeout = self::TIMEOUT * 10;
        } else {
            $timeout = self::TIMEOUT;
        }

        $clickHouse->setTimeout($timeout);
        $clickHouse->setConnectTimeOut($timeout);
        $clickHouse->settings()->set('timeout_before_checking_execution_speed', false);

        $this->_client = $clickHouse;
    }

    /**
     * Возвращает объект-одиночку
     *
     * @param string|null $profile
     * @param array $connectParams
     * @return self
     * @phpstan-ignore-next-line
     * @SuppressWarnings(PHPMD.MethodArgs)
     */
    public static function getInstance(string $profile, array $connectParams = []): self
    {
        if (empty(self::$_instance[$profile])) {
            $clickHouse = new Client($connectParams);
            $clickHouse->database($connectParams['database']);

            self::$_instance[$profile] = new self($clickHouse);
        }

        return self::$_instance[$profile];
    }

    /**
     * Проброс select, дабы кол-во уменьшить вложенность
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
     * Включен ли режим отладки
     *
     * @return bool
     */
    private function _isDebug(): bool
    {
        return Configure::read('debug', false);
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
