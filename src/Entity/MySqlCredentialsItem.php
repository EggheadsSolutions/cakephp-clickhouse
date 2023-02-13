<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Entity;

use InvalidArgumentException;

class MySqlCredentialsItem
{
    /** @var string БД */
    public string $database;

    /** @var string Хост */
    public string $host;

    /** @var int Порт */
    public int $port;

    /** @var string Имя пользователя */
    public string $username;

    /** @var string Пароль */
    public string $password;

    /**
     * Конструктор
     *
     * @param array<mixed> $config
     */
    public function __construct(?array $config)
    {
        if (empty($config) || empty($config['database']) || empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            throw new InvalidArgumentException('Не задана default конфигурация MySQL');
        }
        $this->database = $config['database'];
        $this->host = $config['host'];
        $this->port = (int)($config['port'] ?? 3306);
        $this->username = $config['username'];
        $this->password = $config['password'];
    }
}
