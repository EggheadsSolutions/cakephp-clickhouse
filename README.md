Данная либа создана, чтобы упростить использование ClickHouse в проектах CakePHP, является обёрткой
над https://github.com/smi2/phpClickHouse

Либа подключается через композер:

`./composer.phar require eggheads/cakephp-clickhouse`
или
`composer require eggheads/cakephp-clickhouse`

# Настройка для одного сервера

В app.php указываем следующее:

```php
    'clickHouseServer' => [
        'host' => 'yandexcloud.net',
        'port' => '8443',
        'username' => 'wb',
        'password' => '1',
        'database' => 'analytics',
        'https' => true,
        'sslCA' => CONFIG . 'CA.pem', // ключ к PEM сертификату, например, для Яндекс Облака
        'settings' => [ // необязательный набор настроек
            'insert_distributed_sync' => true,
            'join_algorithm' => 'auto',
            ...
        ],
    ],
```

# Настройка для нескольких серверов

Для подключения к CH требуется настройка стандартного конфига в CakePHP. Обязательно должны присутствовать 2
элемента: `clickHouseServer` и `clickHouseWriters`. Пример конфига:

```php
<?php
return [
    'clickHouseServer' => [ // сервер 1
        'host' => 'yandexcloud.net',
        'port' => '8443',
        'username' => 'wb',
        'password' => '1',
        'database' => 'analytics',
        'https' => true,
        'sslCA' => CONFIG . 'CA.pem',
    ],

    'clickHouseWriters' => [
        'hddNode' => [ // сервер 2
            'host' => 'yandexcloud.net',
            'port' => '8443',
            'username' => 'wb2',
            'password' => '2',
            'database' => 'analytics2',
            'https' => true,
            'sslCA' => CONFIG . 'CA.pem',
        ],
    ],

    // необязательный набор настроек для всех серверов
    'clickHouseSettings' => [
        'insert_distributed_sync' => true,
        'join_algorithm' => 'auto',
        ...
    ],
];
```

# Произвольные запросы

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

# Классы таблиц

Конвенция именования классов: _ИмяТаблицыClickHouseTable_ соответствутет таблице _имяТаблицы_ на сервере.

В классах таблиц, наследуемых от [AbstractClickHouseTable](src/AbstractClickHouseTable.php), указываем следующее:

```php
<?php
declare(strict_types=1);

namespace App\Lib\ClickHouse\Table;

use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

class WbProductsClickHouseTable extends AbstractClickHouseTable
{
    public const TABLE = 'wbProducts'; // указывать, если имя таблицы отличается от *ClickHouseTable
    public const WRITER_CONFIG = 'default'; // указывать в случае необходимости записи в таблицу из clickHouseWriters, либо default - это clickHouseServer

```

Пример класса таблицы с транзакционной записью через [ClickHouseTransaction](src/ClickHouseTransaction.php) (одного
большого куска данных размером 10+Гб):

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
                $transaction->append(['wbId' => $article->wbId, 'cost' => $article->cost]); // если передать несуществующее поле, то вылетит Exception
            }

            if ($transaction->count() > self::PAGE_SIZE) {
                $transaction->commit();
                $transaction = $this->createTransaction();
            }
        }

        if ($transaction->hasData()) {
            $transaction->commit();
        } else {
            $transaction->rollback(); // если не делать rollback(), то при вызове диструктора вылетит Exception
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

# Применение временной таблицы для выбора из подзапроса

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
      AND deliveryDate BETWEEN :dateFrom AND :dateTo",
    ['dateFrom' => '2022-03-01', 'dateTo' => '2022-03-05']
);

$ch = ClickHouse::getInstance();
$ch->select('
    SELECT * FROM wbCabinetRealizationDelivery
    WHERE realizationId GLOBAL IN (SELECT testId FROM {tempTableName} GROUP BY checkDate, wbId',
    ['tempTableName' => $set->getName()],
)->rows();

$ch->select('SELECT testId FROM {table}', ['table' => $set->getName()]);
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

# Тестирование

В проекте, если нет тестового CH, обязательно надо создавать MOKи, чтобы не изменять реальные данные. Примеры MOK

```php
// Версия CakePHP 3.9
MethodMocker::mock(ClickHouseTransaction::class, 'commit')
    ->willReturnValue($this->getMockBuilder(Statement::class)->disableOriginalConstructor()->getMock());

// Версия CakePHP 4
MethodMocker::mock(AbstractClickHouseTable::class, 'select')
    ->willReturnValue($this->createMock(Statement::class));
```
