<?php

namespace App\Http\Requests\Ai;

use App\Services\Ai\Changesets\ChangeTargets;
use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAiScheduleRequest extends FormRequest
{
    /** The EntryScope filters a schedule may save. */
    public const FILTERS = ['query', 'block', 'parent_id', 'language', 'missing_locale', 'data_source', 'missing_dimension', 'folder_id', 'missing_field'];

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'skill' => [$required, 'string', 'max:64'],
            'scope' => [$required, 'array'],
            'scope.type' => ['required_with:scope', Rule::in(ChangeTargets::TYPES)],
            'scope.filters' => ['sometimes', 'array:'.implode(',', self::FILTERS)],
            'scope.filters.*' => ['nullable', 'string', 'max:255'],
            'cron' => [$required, 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if (! \is_string($value) || ! CronExpression::isValidExpression($value)) {
                    $fail('Enter a valid cron expression, for example 0 6 * * 1.');
                }
            }],
            'max_usd' => [$required, 'numeric', 'min:0.01', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
