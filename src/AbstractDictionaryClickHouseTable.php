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
    /** @var string[] Заменяемые параметры подключения в строке создания словаря */
    public const REPLACING_CREDENTIALS = [
        'host',
        'user',
        'password',
        'db',
    ];

    /** @inheritdoc */
    protected function _buildTableName(): string
    {
        $dictName = parent::_buildTableName();
        if (Configure::read('mockClickHouseDictionary') && !Configure::read('isUnitTest')) {
            if (ClickHouseMockCollection::getTableName($dictName)) {
                throw new LogicException('Мок мока');
            }

            $readerClient =  $this->_getReader()->getClient();

            /** @var string|null $database Имя БД */
            $database = $readerClient->settings()->getDatabase();

            if (is_null($database)) {
                new LogicException('Невозможно получить имя базы данных');
            }

            $mySQLConfig = new MySqlCredentialsItem(ConnectionManager::getConfig('default'));

            $mockDictName = Inflector::underscore($mySQLConfig->database . '_') . $dictName; // Имя для словаря-мока

            $originalCreateStatement = $this->_getCreateTableStatement($database . self::TABLE_NAME_DELIMITER . $dictName);
            $mockCreateStatement = $this->_getCreateMockTableStatement($originalCreateStatement, $mySQLConfig, $dictName, $mockDictName);

            $isExistMockDictTable = $this->_isTableExist($database . self::TABLE_NAME_DELIMITER . $mockDictName);
            $currentMockCreateStatement = $isExistMockDictTable ? $this->_getCreateTableStatement($database . self::TABLE_NAME_DELIMITER . $mockDictName) : '';

            if ($mockCreateStatement !== $currentMockCreateStatement) {
                $readerClient->write('DROP DICTIONARY IF EXISTS {table}', ['table' => $mockDictName]);
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
     * @param string $dictName
     * @param string $mockDictName
     * @return string
     */
    private function _getCreateMockTableStatement(string $statement, MySqlCredentialsItem $credentialsItem, string $dictName, string $mockDictName): string
    {
        $credentials = StatementHelper::extractCredentialsFromCreteTableStatement($statement);

        $notFoundParams = array_diff(AbstractDictionaryClickHouseTable::REPLACING_CREDENTIALS, array_keys($credentials));
        if (count($notFoundParams) > 0) {
            throw new LogicException('Не все необходимые поля для замены найдены в строке подключения');
        }

        $statement = str_replace($dictName, $mockDictName, $statement);

        return StatementHelper::replaceCredentialsInCreateTableStatement($statement, [
            $credentials['db'] => $credentialsItem->database,
            $credentials['host'] => $credentialsItem->host,
            $credentials['user'] => $credentialsItem->username,
            $credentials['password'] => $credentialsItem->password,
        ]);
    }
}
