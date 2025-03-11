<?php

namespace App\Casts\Automation;

use App\Services\Automation\ValueObjects\Action;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ActionCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Action
    {
        if ($value === null) {
            return null;
        }

        $data = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid action JSON');
        }

        return Action::fromArray($data);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Action) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode(Action::fromArray($value)->toArray());
        }

        throw new InvalidArgumentException('Invalid action value type');
    }
}
