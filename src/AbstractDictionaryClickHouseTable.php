<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Eggheads\CakephpClickHouse\Entity\MySqlCredentialsItem;
use LogicException;

abstract class AbstractDictionaryClickHouseTable extends AbstractExternalSourceClickHouseTable
{
    /**
     * Пересобирает справочник
     *
     * @return void
     */
    public function reload(): void
    {
        if (!is_null(ClickHouseMockCollection::getMockTable($this->getNamePart(false)))) {
            // Не выполняем перезагрузку, если таблица-словарь замокана.
            return;
        }

        $this->_getReader()->getClient()->write(
            'SYSTEM RELOAD DICTIONARY {table}',
            [
                'table' => $this->getTableName(),
            ]
        );
    }

    /** @inheritdoc */
    protected function _getCreateMockTableStatement(string $statement, string $mockTableName, MySqlCredentialsItem $credentialsItem): string
    {
        $patternReplacement = [
            '/HOST \'[^\']+\'/iu' => sprintf("HOST '%s'", $credentialsItem->host),
            '/PORT \d+/iu' => sprintf("PORT %s", $credentialsItem->port),
            '/USER \'[^\']+\'/iu' => sprintf("USER '%s'", $credentialsItem->username),
            '/PASSWORD \'[^\']+\'/iu' => sprintf("PASSWORD '%s'", $credentialsItem->password),
            '/DB \'[^\']+\'/iu' => sprintf("DB '%s'", $credentialsItem->database),
            '/CREATE DICTIONARY [\w_.]+/iu' => sprintf("CREATE DICTIONARY %s", $mockTableName),
        ];

        $result = preg_replace(array_keys($patternReplacement), array_values($patternReplacement), $statement, 1, $count);
        if ($count !== count($patternReplacement)) {
            throw new LogicException('Ошибки при замене');
        }
        return $result;
    }
}
