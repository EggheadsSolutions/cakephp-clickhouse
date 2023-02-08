<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Entity;

use InvalidArgumentException;

class MySqlCredentialsItem
{
    public string $database;

    public string $host;

    public string $username;

    public string $password;

    public function __construct(?array $config)
    {
        if (empty($config) || empty($config['database']) || empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            throw new InvalidArgumentException('Не задана default конфигурация MySQL');
        }
        $this->database = $config['database'];
        $this->host = $config['host'];
        $this->username = $config['username'];
        $this->password = $config['password'];
    }
}
