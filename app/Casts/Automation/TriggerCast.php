<?php

namespace App\Casts\Automation;

use App\Services\Automation\ValueObjects\Trigger;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TriggerCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Trigger
    {
        if ($value === null) {
            return null;
        }

        $data = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid trigger JSON');
        }

        return Trigger::fromArray($data);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Trigger) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode(Trigger::fromArray($value)->toArray());
        }

        throw new InvalidArgumentException('Invalid trigger value type');
    }
}
