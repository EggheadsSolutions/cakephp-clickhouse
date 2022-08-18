<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse;

use Eggheads\CakephpClickHouse\Tests\ChunksConditionQueryBuilderTest\ChunksConditionQueryBuilderTest;
use InvalidArgumentException;

/**
 * Построение массива условий для разбиения большого запроса на части
 *
 * Основная идея:
 * В ситуациях, когда зарос не укладывается в лимиты памяти, выполнить несколько запросов
 * на непересекающихся частях и объединить результаты
 *
 * Имеются 2 реализации:
 * 1). Основан на уже готовом разбиении некого поля. (Например разбиением по квантилям)
 * @see ClickHouseTableInterface::getChunksIds()
 * 2). Основан на идее, что функция cityHash дает числовое представление с нормальным распределением относительно остатка от деления
 *
 * На выходе всегда массив дополнительных условий для использования в секции WHERE
 *
 * Примеры использования можно посмотреть в тестах
 * @see ChunksConditionQueryBuilderTest
 */
class ChunksConditionQueryBuilder
{
    /** @var string Заменитель для ид */
    public const ID_REPLACEMENT = '%';

    /**
     * Возвращает массив условий для разбиения по чанкам
     *
     * @param string[] $chunks Массив ИД границ разбиения, например ['234', '789']
     * @param string $conditionField Поле, по которому идёт разбиение
     * @param string $fieldReplacement Подстановка для сравнения, % заменяется идентификатором границы
     * @return string[] Массив условий для построения запросов
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
     * @param int $partsCount Число разбиений
     * @param string $conditionField Поле, по которому идет разбиение
     * @return string[] Массив условий для построения запросов
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
