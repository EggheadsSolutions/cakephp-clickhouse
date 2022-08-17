<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Tests\ChunksConditionQueryBuilderTest;

use Cake\TestSuite\TestCase;
use Eggheads\CakephpClickHouse\ChunksConditionQueryBuilder;

class ChunksConditionQueryBuilderTest extends TestCase
{
    /**
     * Тестируем ошибку в конструкторе
     *
     * @return void
     */
    public function testConstructorError(): void
    {
        $this->expectExceptionMessage('$chunks должно содержать минимум одно значение');
        new ChunksConditionQueryBuilder([]);
    }

    /**
     * Тестируем получение массива условий
     *
     * @return void
     */
    public function testResult(): void
    {
        self::assertEquals(
            [
                'toUint64(productId) <= toUint64(123)',
                'toUint64(productId) > toUint64(123)',
            ],
            (new ChunksConditionQueryBuilder(['123']))->getConditionsQueryArray('toUint64(productId)', 'toUint64(%)')
        );

        self::assertEquals(
            [
                'productId <= 123',
                'productId > 123 AND productId <= 345',
                'productId > 345',
            ],
            (new ChunksConditionQueryBuilder(['123', '345']))->getConditionsQueryArray('productId', '%')
        );
    }
}
