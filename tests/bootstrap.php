<?php
declare(strict_types=1);

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

define('TMP', __DIR__ . '/tmp');
