<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Eggheads\CakephpClickHouse\Entity\MySqlCredentialsItem;
use LogicException;

class AbstractMySqlEngineClickHouseTable extends AbstractExternalSourceClickHouseTable
{
    /** @inheritdoc */
    protected function _getCreateMockTableStatement(string $statement, string $mockTableName, MySqlCredentialsItem $credentialsItem): string
    {
        $connectionRegExp = '/MySQL\([^\)]+\)/iu';

        if (preg_match($connectionRegExp, $statement, $matches) === 1) {
            $originalCredentials = explode(', ', $matches[0]);
            if (!key_exists(2, $originalCredentials)) {
                throw new LogicException('Источником CH таблицы не является MySQL');
            }
            $originalMySqlTableName = trim($originalCredentials[2], "'");
        } else {
            throw new LogicException('Источником CH таблицы не является MySQL');
        }

        $patternReplacement = [
            $connectionRegExp => sprintf(
                "MySQL('%s:%s', '%s', '%s', '%s', '%s')",
                $credentialsItem->host,
                $credentialsItem->port,
                $credentialsItem->database,
                $originalMySqlTableName,
                $credentialsItem->username,
                $credentialsItem->password
            ),
            '/CREATE TABLE [\w_.]+/iu' => sprintf("CREATE TABLE %s", $mockTableName),
        ];

        $result = preg_replace(array_keys($patternReplacement), array_values($patternReplacement), $statement, 1, $count);
        if ($count !== count($patternReplacement)) {
            throw new LogicException('Ошибки при замене');
        }
        return $result;
    }
}
