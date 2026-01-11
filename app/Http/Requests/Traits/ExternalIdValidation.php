<?php

namespace App\Http\Requests\Traits;

use Illuminate\Validation\Rule;

trait ExternalIdValidation
{
    /**
     * Get the external_id validation rule based on the x-enforce-external-id header.
     *
     * If 'x-enforce-external-id' header is present, external_id becomes required with unique constraint.
     * Otherwise, it's optional and accepts nullable strings up to 36 characters.
     *
     * @param string $modelClass The fully qualified model class name
     * @param string|null $ignoreId Optional ID to ignore in unique check (for updates)
     * @return array|\Illuminate\Contracts\Validation\ValidationRule|string
     */
    protected function externalIdRule(string $modelClass, ?string $ignoreId = null)
    {
        if ($this->hasHeader('x-enforce-external-id')) {
            $model = new $modelClass();
            $rule = Rule::unique($model->getConnectionName() . '.' . $model->getTable(), 'external_id');

            if ($ignoreId) {
                $rule = $rule->ignore($ignoreId);
            }

            return [
                'required',
                'string',
                'max:36',
                'uuid',
                $rule,
            ];
        }

        return 'sometimes|nullable|string|max:36';
    }
}
