<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use InvalidArgumentException;
use LogicException;

class StatementHelper
{
    /**
     * Выделение из строки создания таблицы массива параметров подключения
     *
     * @param string $statement
     * @return array
     */
    public static function extractCredentialsFromCreteTableStatement(string $statement): array
    {
        if (preg_match('/SOURCE\s*\(\s*MYSQL\s*\(\s*(?<optionsString>.+?)\s*\)\s*\)/ius', $statement, $inputMatches) !== 1) {
            throw new InvalidArgumentException('Не является строкой создания таблицы словаря над MySQL');
        }

        $optionsString = $inputMatches['optionsString'];

        $result = [];
        $offset = 0;
        do {
            if (preg_match('/\G(?<key>\w+)\s+(?<value>\w+|\'[^\']+\')\s*/ius', $optionsString, $optionMatches, 0, $offset) !== 1) {
                return $result;
            }

            $result[mb_strtolower($optionMatches['key'])] = trim($optionMatches['value'], '\'');
            $offset += strlen($optionMatches[0]);
        } while ($offset < strlen($optionsString));

        return $result;
    }

    /**
     * Замена параметров подключения
     *
     * @param string $statement
     * @param array $replace
     * @return string
     */
    public static function replaceCredentialsInCreateTableStatement(string $statement, array $replace): string
    {
        $result = str_replace(array_keys($replace), array_values($replace), $statement, $count);
        if ($count !== count($replace)) {
            throw new LogicException('Ошибка в изменении');
        }
        return $result;
    }
}
