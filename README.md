Данная либа создана, чтобы упростить использование ClickHouse в проектах CakePHP.

Либа подключается через композер:

`./composer.phar require eggheads.solutions/cakephp-clickhouse`
или
`composer require eggheads.solutions/cakephp-clickhouse`

Для подключения к CH требуется настройка стандартного конфига в CakePHP. Обязательно должны присутствовать 2
элемента: `clickHouseServer` и `clickHouseWriters`. Пример конфига:

```php
<?php
return [
    'clickHouseServer' => [
        'host' => 'yandexcloud.net',
        'port' => '8443',
        'username' => 'wb',
        'password' => '1',
        'database' => 'analytics',
        'https' => true,
        'sslCA' => CONFIG . 'CA.pem',
    ],

    'clickHouseWriters' => [
        'hddNode' => [
            'host' => 'yandexcloud.net',
            'port' => '8443',
            'username' => 'wb2',
            'password' => '2',
            'database' => 'analytics2',
            'https' => true,
            'sslCA' => CONFIG . 'CA.pem',
        ],
    ],

];
```

Пример класса таблицы:

```php
<?php
declare(strict_types=1);

namespace App\Lib\ClickHouse\Table;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class WbProductsClickHouseTable extends AbstractClickHouseTable
{
    public const TABLE = 'wbProducts';
    public const WRITER_CONFIG = 'ssdNode';

    /** @var int Размер порции данных при отправке в транзакции */
    private const PAGE_SIZE = 100000;

    public function example(array $articles): void
    {
        $transaction = $this->createTransaction();

        foreach ($articles as $article) {
            if ($article->cost !== null) {
                $transaction->append(['wbId' => $article->wbId]);
            }

            if ($transaction->count() > self::PAGE_SIZE) {
                $transaction->commit();
                $transaction = $this->createTransaction();
            }
        }

        if ($transaction->hasData()) {
            $transaction->commit();
        } else {
            $transaction->rollback();
        }
    }
}
```

Пример выполнения произвольного запроса:

```php
$rows = ClickHouse::getInstance()->select(
    'SELECT wbId FROM wbProducts WHERE wbId = :wbProductId',
    ['wbProductId' => $wbProductId]
)->rows();

foreach ($rows as $record) {
    //
}
```

В проекте, если нет тестового CH, обязательно надо создавать MOCKи, чтобы не изменять реальные данные. Примеры MOCK

```php
// Версия CakePHP 3.9
MethodMocker::mock(ClickHouseTransaction::class, 'commit')
    ->willReturnValue($this->getMockBuilder(Statement::class)->disableOriginalConstructor()->getMock());

// Версия CakePHP 4
MethodMocker::mock(AbstractClickHouseTable::class, 'select')
    ->willReturnValue($this->createMock(Statement::class));
```
