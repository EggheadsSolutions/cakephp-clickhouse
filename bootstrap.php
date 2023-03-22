<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Eggheads\CakephpClickHouse\AbstractClickHouseTable;

define(
    'CLICKHOUSE_CONFIG',
    [
        'host' => env('clickhouse_host', 'localhost'),
        'port' => env('clickhouse_port', '8123'),
        'username' => env('clickhouse_username', ''),
        'password' => env('clickhouse_password', ''),
        'database' => env('clickhouse_database', 'default'),
    ]
);

define('TMP', __DIR__ . '/../tmp/');

Configure::write('clickHouseServer', CLICKHOUSE_CONFIG);
Configure::write(
    'clickHouseWriters',
    [
        'writer' => CLICKHOUSE_CONFIG,
        'ssdNode' => CLICKHOUSE_CONFIG,
        'temp' => CLICKHOUSE_CONFIG,
    ]
);

ConnectionManager::setConfig([
    'default' => [
        'database' => 'fake_db',
        'port' => '3306',
        'username' => 'fake_user',
        'password' => 'fake_password',
        'host' => 'fake_host',
    ],
]);

Cache::setConfig([
    AbstractClickHouseTable::CACHE_PROFILE => [
        'className' => ArrayEngine::class,
    ],
]);
