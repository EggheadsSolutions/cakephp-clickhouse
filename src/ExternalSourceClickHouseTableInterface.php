<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Eggheads\CakephpClickHouse\Entity\MySqlCredentialsItem;

interface ExternalSourceClickHouseTableInterface
{
    /**
     * Получить выражение для создания таблицы-дублёра
     *
     * @param string $statement
     * @param string $mockTableName
     * @param MySqlCredentialsItem $credentialsItem
     * @return string
     */
    public function makeCreateDoublerStatement(string $statement, string $mockTableName, MySqlCredentialsItem $credentialsItem): string;

    /**
     * Получить выражение для удаления таблицы-дублёра
     *
     * @param string $doublerFullName
     * @return string
     */
    public function makeDropDoublerStatement(string $doublerFullName): string;
}
