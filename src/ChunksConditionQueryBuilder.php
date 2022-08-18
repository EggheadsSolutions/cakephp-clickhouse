<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use InvalidArgumentException;

class ChunksConditionQueryBuilder
{
    /** @var string Заменитель для ид */
    public const ID_REPLACEMENT = '%';

    /**
     * Возвращает массив условий для разбиения по чанкам
     *
     * @param string[] $chunks ИД границ разбиения, например ['234', '789']
     * @param string $conditionField
     * @param string $fieldReplacement
     * @return string[]
     */
    public function getConditionsQueryByChunks(array $chunks, string $conditionField, string $fieldReplacement = '%'): array
    {
        if (count($chunks) === 0) {
            throw new InvalidArgumentException('$chunks должно содержать минимум одно значение');
        }

        if (!str_contains($fieldReplacement, self::ID_REPLACEMENT)) {
            throw new InvalidArgumentException('$fieldReplacement должен содержать знак ' . self::ID_REPLACEMENT);
        }
        $previousChunk = null;
        $result = [];

        foreach ($chunks as $key => $chunk) {
            if ($key === array_key_first($chunks)) {
                $result[] = "$conditionField <= " . str_replace(self::ID_REPLACEMENT, $chunk, $fieldReplacement);
            }
            if (!is_null($previousChunk)) {
                $result[] = "$conditionField > " . str_replace(self::ID_REPLACEMENT, $previousChunk, $fieldReplacement) .
                    " AND $conditionField <= " . str_replace(self::ID_REPLACEMENT, $chunk, $fieldReplacement);
            }
            if ($key === array_key_last($chunks)) {
                $result[] = "$conditionField > " . str_replace(self::ID_REPLACEMENT, $chunk, $fieldReplacement);
            }
            $previousChunk = $chunk;
        }
        return $result;
    }

    /**
     * Возвращает массив условий для разбиения на основании остатка от деления хеша
     *
     * @param int $partsCount число разбиений
     * @param string $conditionField поле, по которому идет разбиение
     * @return string[]
     */
    public function getConditionsQueryByModulo(int $partsCount, string $conditionField): array
    {
        if ($partsCount <= 1) {
            throw new InvalidArgumentException('Число разбиений должно быть положительным, больше 1');
        }
        $result = [];
        for ($remainder = 0; $remainder < $partsCount; $remainder++) {
            $result[] = "modulo(cityHash64($conditionField), $partsCount) = $remainder";
        }
        return $result;
    }
}
