<?php

namespace App\Services\Automation\ValueObjects;

use App\Services\Automation\Enums\TriggerType;

class Trigger
{
    public function __construct(
        private readonly TriggerType $type,
        private readonly ?array $config = null
    ) {}

    public function type(): TriggerType
    {
        return $this->type;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function toArray(): array
    {
        $config = $this->config;

        if (is_array($config) && ! isset($config['table']) && isset($config['resource'])) {
            $config['table'] = $config['resource'];
        }

        return [
            'type' => $this->type->value,
            'config' => $config,
        ];
    }

    public static function fromArray(?array $data): ?self
    {
        if (! $data) {
            return null;
        }

        return new self(
            TriggerType::from($data['type']),
            $data['config'] ?? null
        );
    }
}
