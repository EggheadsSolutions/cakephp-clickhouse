<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Utility\Inflector;
use Eggheads\CakephpClickHouse\Entity\MySqlCredentialsItem;
use LogicException;

abstract class AbstractExternalSourceClickHouseTable extends AbstractClickHouseTable
{
    /** @inheritdoc */
    protected function _buildTableName(): string
    {
        $originalTableName = parent::_buildTableName();
        if (Configure::read('mockClickHouseDictionary') && !(defined('TEST_MODE') && TEST_MODE)) {
            if (ClickHouseMockCollection::getTableName($originalTableName)) {
                throw new LogicException('Мок мока');
            }

            $readerClient = $this->_getReader()->getClient();

            /** @var string|null $database Имя БД */
            $database = $readerClient->settings()->getDatabase();

            if (empty($database)) {
                throw new LogicException('Невозможно получить имя базы данных');
            }

            $mySQLConfig = new MySqlCredentialsItem(ConnectionManager::getConfig('default'));

            $mockTableName = Inflector::underscore($mySQLConfig->database . '_') . $originalTableName; // Имя для словаря-мока
            $mockTableFullName = $database . self::TABLE_NAME_DELIMITER . $mockTableName;

            $originalCreateStatement = $this->_getReader()->getCreateTableStatement($database . self::TABLE_NAME_DELIMITER . $originalTableName);
            $mockCreateStatement = $this->_getCreateMockTableStatement($originalCreateStatement, $mockTableFullName, $mySQLConfig);

            $isExistMockDictTable = $this->_getReader()->isTableExist($mockTableFullName);
            $currentMockCreateStatement = $isExistMockDictTable ? $this->_getReader()->getCreateTableStatement($mockTableFullName) : '';

            if ($mockCreateStatement !== $currentMockCreateStatement) {
                $this->_dropTableIfExist($mockTableName);
                $readerClient->write($mockCreateStatement);
            }
            return $mockTableName;
        }
        return $originalTableName;
    }

    /**
     * Получить выражение для создания мок-таблицы
     *
     * @param string $statement
     * @param string $mockTableName
     * @param MySqlCredentialsItem $credentialsItem
     * @return string
     */
    abstract protected function _getCreateMockTableStatement(string $statement, string $mockTableName, MySqlCredentialsItem $credentialsItem): string;

    /**
     * Удаляет словарь или таблицу, если она существует
     *
     * @param string $tableName
     * @return void
     */
    private function _dropTableIfExist(string $tableName)
    {
        $entity = $this instanceof AbstractDictionaryClickHouseTable ? 'DICTIONARY' : 'TABLE';
        $this->_getReader()->getClient()->write("DROP $entity IF EXISTS {table}", ['table' => $tableName]);
    }
}
