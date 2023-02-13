<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use Eggheads\CakephpClickHouse\Entity\MySqlCredentialsItem;
use LogicException;

abstract class AbstractDictionaryClickHouseTable extends AbstractClickHouseTable
{
    /** @inheritdoc */
    protected function _buildTableName(): string
    {
        $dictName = parent::_buildTableName();
        if (Configure::read('mockClickHouseDictionary') && !(defined('TEST_MODE') && TEST_MODE)) {
            if (ClickHouseMockCollection::getTableName($dictName)) {
                throw new LogicException('Мок мока');
            }

            $readerClient = $this->_getReader()->getClient();

            /** @var string|null $database Имя БД */
            $database = $readerClient->settings()->getDatabase();

            if (empty($database)) {
                throw new LogicException('Невозможно получить имя базы данных');
            }

            $mySQLConfig = new MySqlCredentialsItem(ConnectionManager::getConfig('default'));

            $mockDictName = Inflector::underscore($mySQLConfig->database . '_') . $dictName; // Имя для словаря-мока
            $mockDictFullName = $database . self::TABLE_NAME_DELIMITER . $mockDictName;

            $originalCreateStatement = $this->_getCreateTableStatement($database . self::TABLE_NAME_DELIMITER . $dictName);
            $mockCreateStatement = $this->_getCreateMockTableStatement($originalCreateStatement, $mockDictFullName, $mySQLConfig);

            $isExistMockDictTable = $this->_isTableExist($mockDictFullName);
            $currentMockCreateStatement = $isExistMockDictTable ? $this->_getCreateTableStatement($mockDictFullName) : '';

            if ($mockCreateStatement !== $currentMockCreateStatement) {
                $readerClient->write('DROP DICTIONARY IF EXISTS {table}', ['table' => $mockDictFullName]);
                $readerClient->write($mockCreateStatement);
            }
            return $mockDictName;
        }
        return $dictName;
    }

    /**
     * Пересобирает справочник
     *
     * @return void
     */
    public function reload(): void
    {
        $this->_getReader()->getClient()->write(
            'SYSTEM RELOAD DICTIONARY {table}',
            [
                'table' => $this->getTableName(),
            ]
        );
    }

    /**
     * Проверить существование таблицы
     *
     * @param string $tableName
     * @return bool
     */
    private function _isTableExist(string $tableName): bool
    {
        return (bool)$this->select('EXISTS TABLE {dict}', [
            'dict' => $tableName,
        ])->fetchOne('result');
    }

    /**
     * Получить выражение для создания таблицы
     *
     * @param string $dictName
     * @return string
     */
    private function _getCreateTableStatement(string $dictName): string
    {
        return $this->select('SHOW CREATE TABLE {dict}', [
            'dict' => $dictName,
        ])->fetchOne('statement');
    }

    /**
     * Получить выражение для создания мок-таблицы
     *
     * @param string $statement
     * @param MySqlCredentialsItem $credentialsItem
     * @param string $mockDictName
     * @return string
     */
    private function _getCreateMockTableStatement(string $statement, string $mockDictName, MySqlCredentialsItem $credentialsItem): string
    {
        $patternReplacement = [
            '/HOST \'[^\']+\'/iu' => sprintf("HOST '%s'", $credentialsItem->host),
            '/PORT \d+/iu' => sprintf("PORT %s", $credentialsItem->port),
            '/USER \'[^\']+\'/iu' => sprintf("USER '%s'", $credentialsItem->username),
            '/PASSWORD \'[^\']+\'/iu' => sprintf("PASSWORD '%s'", $credentialsItem->password),
            '/DB \'[^\']+\'/iu' => sprintf("DB '%s'", $credentialsItem->database),
            '/CREATE DICTIONARY [\w_.]+/iu' => sprintf("CREATE DICTIONARY %s", $mockDictName),
        ];

        $result = preg_replace(array_keys($patternReplacement), array_values($patternReplacement), $statement, 1, $count);
        if ($count !== count($patternReplacement)) {
            throw new LogicException('Ошибки при замене');
        }
        return $result;
    }
}
