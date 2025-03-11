<?php

namespace App\Services\Content\Schema;

use App\Services\Content\Schema\Types\TypeHandlerInterface;
use InvalidArgumentException;

class TypeRegistry
{
    protected array $handlers = [];

    /**
     * Register a type handler
     */
    public function register(TypeHandlerInterface $handler): self
    {
        $this->handlers[$handler->getType()] = $handler;
        return $this;
    }

    /**
     * Get a handler for a specific type
     */
    public function getHandler(string $type): TypeHandlerInterface
    {
        if (!isset($this->handlers[$type])) {
            throw new InvalidArgumentException("No handler registered for type '{$type}'");
        }

        return $this->handlers[$type];
    }

    /**
     * Check if a handler exists for a type
     */
    public function hasHandler(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Get all registered handlers
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
}
