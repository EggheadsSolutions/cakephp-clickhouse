<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Log\Log;
use ClickHouseDB\Statement;
use Eggheads\CakephpClickHouse\Exception\FieldNotFoundException;
use Exception;
use LogicException;

/**
 * Аналог транзакции через отправку CSV файла.
 * Позволяет писать огромные данные
 */
class ClickHouseTransaction
{
    private const WORK_DIR = 'clickHouse';

    /** @var string Ошибка CH, при которой делать повторную попытку */
    public const CH_ERROR_MESSAGE = 'necessary data rewind wasn\'t possible';

    /** @var int Максимальное кол-во попыток запроса */
    public const MAX_ATTEMPT_COUNT = 2;

    /** @var int Секунд ожидания */
    public const BAD_ATTEMPT_WAIT_SECONDS = 30;

    /** @var string */
    private string $_tableName;

    /** @var string[] */
    private array $_schemaFields;

    /** @var string[] */
    private array $_saveFields = [];

    /** @var ?resource */
    private $_stream;

    /** @var string */
    private string $_filePath;

    /** @var int Кол-во добавленных строк */
    private int $_countData = 0;

    /** @var bool Было ли брошено исключение */
    private bool $_hasException = false;

    /** @var ClickHouse */
    private ClickHouse $_clickHouse;

    /**
     * ClickHouseTransaction constructor.
     *
     * @param ClickHouse $clickHouse
     * @param string $tableName
     * @param string[] $schemaFieldNames
     * @throws LogicException
     */
    public function __construct(ClickHouse $clickHouse, string $tableName, array $schemaFieldNames)
    {
        $workDir = TMP . self::WORK_DIR;
        if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new LogicException(sprintf('Directory "%s" was not created', $workDir));
        }

        $this->_filePath = tempnam($workDir, $tableName . '-');
        $this->_stream = fopen($this->_filePath, 'w');

        $this->_schemaFields = $schemaFieldNames;
        $this->_tableName = $tableName;
        $this->_clickHouse = $clickHouse;
    }

    /**
     * Проверка на отправку всех данных
     *
     * @throws LogicException
     */
    public function __destruct()
    {
        if (!empty($this->_stream) && $this->_hasException === false) {
            throw new LogicException('Данные не были отправлены в ClickHouse');
        }
    }

    /**
     * Добавляем данные в виде ассоциативного массива
     *
     * @param array<string,string|int|float|string[]|int[]|float[]|null> $data
     * @throws FieldNotFoundException
     * @throws LogicException
     */
    public function append(array $data): void
    {
        if (empty($this->_stream)) {
            throw new LogicException('Данные были уже отправлены, добавление новой порции невозможно.');
        }

        $saveRow = [];
        foreach ($this->_schemaFields as $fieldName) {
            if (!array_key_exists($fieldName, $data)) {
                continue;
            }
            $saveRow[$fieldName] = $data[$fieldName];
            if (!in_array($fieldName, $this->_saveFields, true)) {
                $this->_saveFields[] = $fieldName;
            }
        }
        if (empty($saveRow)) {
            $this->_hasException = true;
            throw (new FieldNotFoundException('Не найдены поля'))
                ->setContext(['saveData' => $data, 'fields' => $this->_schemaFields]);
        }

        fwrite($this->_stream, json_encode($saveRow, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        $this->_countData++;
    }

    /**
     * Есть ли данные на сохранение
     *
     * @return bool
     */
    public function hasData(): bool
    {
        return $this->_countData > 0;
    }

    /**
     * Кол-во добавленных строк
     *
     * @return int
     */
    public function getCount(): int
    {
        return $this->_countData;
    }

    /**
     * Отправляем данные в ClickHouse
     *
     * @return Statement
     * @throws LogicException
     * @throws Exception
     */
    public function commit(): Statement
    {
        fclose($this->_stream);
        $this->_stream = null;

        if ($this->getCount() === 0) {
            unlink($this->_filePath);
            throw new LogicException('Пытаемся сохранить несуществующие данные');
        }

        $cnt = 0;
        /** @phpstan-ignore-next-line */
        while ($cnt++ < self::MAX_ATTEMPT_COUNT) {
            try {
                $result = $this->_clickHouse->getClient()->insertBatchFiles($this->_tableName, [$this->_filePath], $this->_saveFields, 'JSONEachRow');
                $this->_stream = null;
                $this->_countData = 0;
                unlink($this->_filePath);

                return array_pop($result);
            } catch (Exception $exception) {
                if ($cnt < self::MAX_ATTEMPT_COUNT && $exception->getMessage() === self::CH_ERROR_MESSAGE) {
                    sleep(self::BAD_ATTEMPT_WAIT_SECONDS);
                } else {
                    Log::error('ClickHouse transaction save error: ' . $exception->getMessage());
                    throw $exception;
                }
            }
        }
    }

    /** Отменяем запись */
    public function rollback(): void
    {
        if (!$this->_stream) {
            return;
        }

        fclose($this->_stream);
        $this->_stream = null;
        $this->_countData = 0;
        unlink($this->_filePath);
    }
}
