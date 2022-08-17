<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use InvalidArgumentException;

class ChunksConditionQueryBuilder
{
    public const ID_REPLACEMENT = '%';

    /**
     * Массив ИД - границ чанков
     *
     * @var string[]
     */
    private $_chunks;

    /**
     * @param $chunks
     */
    public function __construct($chunks)
    {
        if (count($chunks) === 0) {
            throw new InvalidArgumentException('$chunks должно содержать минимум одно значение');
        }
        $this->_chunks = $chunks;
    }

    /**
     * Возвращает массив условий для разбиения по чанкам
     *
     * @param string $conditionField
     * @param string $fieldReplacement
     * @return array
     */
    public function getConditionsQueryArray(string $conditionField, string $fieldReplacement = '%'): array
    {
        if (!str_contains($fieldReplacement, self::ID_REPLACEMENT)) {
            throw new InvalidArgumentException('$fieldReplacement должен содержать знак ' . self::ID_REPLACEMENT);
        }
        $previousChunk = null;
        $result = [];

        foreach ($this->_chunks as $key => $chunk) {
            if ($key === array_key_first($this->_chunks)) {
                $result[] = "$conditionField <= " . str_replace(self::ID_REPLACEMENT, $chunk, $fieldReplacement);
            }
            if (!is_null($previousChunk)) {
                $result[] = "$conditionField > " . str_replace(self::ID_REPLACEMENT, $previousChunk, $fieldReplacement) .
                " AND $conditionField <= " . str_replace(self::ID_REPLACEMENT, $chunk, $fieldReplacement);
            }
            if ($key === array_key_last($this->_chunks)) {
                $result[] = "$conditionField > " . str_replace(self::ID_REPLACEMENT, $chunk, $fieldReplacement);
            }
            $previousChunk = $chunk;
        }
        return $result;
    }
}
