<?php

namespace App\Services\Automation\ValueObjects;

use App\Services\Automation\Enums\ActionType;
use InvalidArgumentException;

class Action
{
    public function __construct(
        private readonly ActionType $type,
        private readonly array      $config
    )
    {
        match ($this->type) {
            ActionType::WEBHOOK => $this->validateWebhookConfig(),
            ActionType::EMAIL => $this->validateEmailConfig(),
            ActionType::VOID => null,
        };
    }

    protected function validateWebhookConfig(): void
    {
        if (!isset($this->config['url'])) {
            throw new InvalidArgumentException('Webhook action requires URL');
        }

        if (!isset($this->config['method'])) {
            throw new InvalidArgumentException('Webhook action requires HTTP method');
        }

        if (!in_array(strtoupper($this->config['method']), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'])) {
            throw new InvalidArgumentException('Invalid HTTP method');
        }
    }

    protected function validateEmailConfig(): void
    {
        if (!isset($this->config['to']) || empty($this->config['to'])) {
            throw new InvalidArgumentException('Email action requires at least one recipient');
        }

        if (!isset($this->config['subject'])) {
            throw new InvalidArgumentException('Email action requires subject');
        }

        if (!isset($this->config['body'])) {
            throw new InvalidArgumentException('Email action requires body');
        }
    }

    public function type(): ActionType
    {
        return $this->type;
    }

    public function config(string $key = null, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'config' => $this->config
        ];
    }

    public static function fromArray(?array $data): ?self
    {
        if (!$data) {
            return null;
        }

        return new self(
            ActionType::from($data['type']),
            $data['config'] ?? []
        );
    }
}
