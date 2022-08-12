Данная либа создана, чтобы упростить использование ClickHouse в проектах CakePHP.

Либа подключается через композер:

`./composer.phar require eggheads/cakephp-clickhouse`
или
`composer require eggheads/cakephp-clickhouse`

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

Конвенция именования классов: _ИмяТаблицыClickHouseTable_ соответствутет таблице _имяТаблицы_ на сервере.

Пример класса таблицы:

```php
<?php
declare(strict_types=1);

namespace App\Lib\ClickHouse\Table;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class WbProductsClickHouseTable extends AbstractClickHouseTable
{
    public const TABLE = 'wbProducts'; // указывать, если имя таблицы отличается от *ClickHouseTable
    public const READER_CONFIG = 'anotherNode'; // указывать в случае в случае работы с другим сервером (не clickHouseServer из конфигурации)
    public const WRITER_CONFIG = 'ssdNode'; // указывать в случае необходимости записи в таблицу

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

Пример минимально необходимого класса, который именуется согласно конвенции, и используется только для чтения:

```php
<?php
declare(strict_types=1);

namespace App\Lib\ClickHouse\Table;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class WbProductsClickHouseTable extends AbstractClickHouseTable
{

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

## Применение временной таблицы для выбора из подзапроса

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
формата [Memory](https://clickhouse.com/docs/ru/engines/table-engines/special/memory):

```php
$set = new TempTableClickHouse(
    'Test',
    ['testId' => 'String'],
    "SELECT DISTINCT realizationId
    FROM wbCabinetSupplierDelivery
    WHERE wbConfigId IN (4)
      AND deliveryDate BETWEEN '2022-03-01' AND '2022-03-05'"
);

$ch = ClickHouse::getInstance();
$ch->select('
    SELECT * FROM wbCabinetRealizationDelivery
    WHERE realizationId IN ' . $set->getName() . ' GROUP BY checkDate, wbId'
)->rows();

$ch->select('SELECT testId FROM '. $set->getName())
```

Таким образом _TempTableClickHouse_ создаёт временную таблицу, которая участвует в нескольких местах сложного запроса,
например, при выборке _IN_. А после выполнения скрипта временная таблица удаляется через деструктор.

Если необходимо создать временную таблицу на основе существующей:

```php
$table = TempTableClickHouse::createFromTable(
    'clone',
    TestClickHouseTable::getInstance(),
    "SELECT '1', 'bla-bla', 3.0, '2020-08-04 09:00:00'"
);
```
