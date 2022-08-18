<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\ChunksConditionQueryBuilderTest;

use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\ChunksConditionQueryBuilder;

class ChunksConditionQueryBuilderTest extends TestCase
{
    /**
     * Тестируем ошибку в параметрах
     *
     * @return void
     */
    public function testParamError(): void
    {
        $this->expectExceptionMessage('$chunks должно содержать минимум одно значение');
        (new ChunksConditionQueryBuilder())->getConditionsQueryByChunks([], 'productId');
    }

    /**
     * Тестируем получение массива условий по чанкам
     *
     * @return void
     */
    public function testResult(): void
    {
        $queryBuilder = new ChunksConditionQueryBuilder();
        self::assertEquals(
            [
                'toUint64(productId) <= toUint64(123)',
                'toUint64(productId) > toUint64(123)',
            ],
            $queryBuilder->getConditionsQueryByChunks(['123'], 'toUint64(productId)', 'toUint64(%)')
        );

        self::assertEquals(
            [
                'productId <= 123',
                'productId > 123 AND productId <= 345',
                'productId > 345',
            ],
            $queryBuilder->getConditionsQueryByChunks(['123', '345'], 'productId')
        );
    }

    /**
     * Тестируем разбиение на основании остатка от деления
     *
     * @return void
     */
    public function testModulo(): void
    {
        $queryBuilder = new ChunksConditionQueryBuilder();
        self::assertEquals(
            [
                'modulo(cityHash64(productId), 2) = 0',
                'modulo(cityHash64(productId), 2) = 1',
            ],
            $queryBuilder->getConditionsQueryByModulo(2, 'productId')
        );
    }
}
