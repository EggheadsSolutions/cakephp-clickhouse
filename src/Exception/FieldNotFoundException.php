<?php
declare(strict_types=1);

namespace Eggheads\CakephpClickHouse\Exception;

use Exception;

class FieldNotFoundException extends Exception
{
    /**
     * Доп инфа для логов
     *
     * @var array<string, mixed>
     */
    private array $_context = [];

    /**
     * Получение доп информация при логировании Exception
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->_context;
    }

    /**
     * Добавление доп информация при логировании Exception
     *
     * @param array<string, mixed> $context
     * @return self
     */
    public function setContext(array $context): self
    {
        $this->_context = $context;

        return $this;
    }
}
