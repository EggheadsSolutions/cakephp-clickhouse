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

В проекте, если нет тестового CH, обязательно надо создавать MOKи, чтобы не изменять реальные данные. Примеры MOK

```php
// Версия CakePHP 3.9
MethodMocker::mock(ClickHouseTransaction::class, 'commit')
    ->willReturnValue($this->getMockBuilder(Statement::class)->disableOriginalConstructor()->getMock());

// Версия CakePHP 4
MethodMocker::mock(AbstractClickHouseTable::class, 'select')
    ->willReturnValue($this->createMock(Statement::class));
```

## Применение SET для выбора из подзапроса

Запрос вида:

```php
ClickHouse::getInstance()->select("
SELECT * FROM wbCabinetRealizationDelivery
WHERE realizationId GLOBAL IN (SELECT DISTINCT realizationId
FROM wbCabinetSupplierDelivery
WHERE wbConfigId IN (4)
  AND deliveryDate BETWEEN '2022-03-01' AND '2022-03-05')
GROUP BY checkDate, wbId")->rows();
```

Где подзапрос может повторяться несколько раз, можно применить промежуточную временную таблицу
формата [Set](https://clickhouse.com/docs/ru/engines/table-engines/special/set):

```php
$set = new QueryClickHouseSet(['String'], "SELECT DISTINCT realizationId
FROM wbCabinetSupplierDelivery
WHERE wbConfigId IN (4)
  AND deliveryDate BETWEEN '2022-03-01' AND '2022-03-05'", 'default');

ClickHouse::getInstance()->select("
SELECT * FROM wbCabinetRealizationDelivery
WHERE realizationId IN " . $set->getName() . " GROUP BY checkDate, wbId")->rows();
```

Таким образом _QueryClickHouseSet_ создаёт временную таблицу, которая участвует в нескольких
местах при выборке _IN_. А после выполнения скрипта временная таблица удаляется через
деструктор.
