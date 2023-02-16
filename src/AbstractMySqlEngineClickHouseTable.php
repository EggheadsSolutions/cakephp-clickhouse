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
        $originalMySqlTableName = '';
        $patternReplacement = [
            '/MySQL\([^\)]+\)/iu' => sprintf(
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
