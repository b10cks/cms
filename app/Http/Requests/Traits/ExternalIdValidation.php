<?php

namespace App\Http\Requests\Traits;

use Illuminate\Validation\Rule;

trait ExternalIdValidation
{
    /**
     * Get the external_id validation rule based on the x-enforce-external-id header.
     *
     * If 'x-enforce-external-id' header is present, external_id becomes required with unique constraint,
     * and format-specific rules apply based on the header value ('uuid', 'ulid', or 'integer').
     * If the value is not in the list, it uses required, string, max:36, and unique.
     * Otherwise, it's optional and accepts nullable strings up to 36 characters.
     *
     * @param string $modelClass The fully qualified model class name
     * @param string|null $ignoreId Optional ID to ignore in unique check (for updates)
     * @return array|\Illuminate\Contracts\Validation\ValidationRule|string
     */
    protected function externalIdRule(string $modelClass, ?string $ignoreId = null): array|\Illuminate\Contracts\Validation\ValidationRule|string
    {
        $headerValue = request()->header('x-enforce-external-id');
        $model = new $modelClass();

        if ($headerValue) {
            $rule = Rule::unique($model->getConnectionName() . '.' . $model->getTable(), 'external_id');

            if ($ignoreId) {
                $rule = $rule->ignore($ignoreId, $model->getKeyName());
            }

            $baseRules = ['required', 'string', 'max:36', $rule];

            return match (strtolower($headerValue)) {
                'uuid' => array_merge($baseRules, ['uuid']),
                'ulid' => array_merge($baseRules, ['ulid']),
                default => $baseRules
            };
        }

        return ['sometimes', 'nullable', 'string', 'max:36'];
    }
}
