<?php

namespace App\Http\Requests\Traits;

use App\Services\Space\DataSourceShapeValidator;
use Illuminate\Validation\Rule;

trait ValidatesDataSourceShape
{
    /**
     * @return array<string, mixed>
     */
    protected function shapeRules(): array
    {
        return [
            'shape' => 'nullable|array',
            'shape.*.key' => 'required|string|max:50|regex:/^[a-zA-Z0-9_\-]+$/|distinct',
            'shape.*.type' => ['required', 'string', Rule::in(DataSourceShapeValidator::TYPES)],
            'shape.*.name' => 'nullable|string|max:100',
            'shape.*.description' => 'nullable|string',
            'shape.*.required' => 'boolean',
            'shape.*.options' => 'nullable|array',
            'shape.*.options.*.name' => 'required|string|max:100',
            'shape.*.options.*.value' => 'required|string|max:100',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $shape = $this->input('shape');

                if (!is_array($shape)) {
                    return;
                }

                $errors = app(DataSourceShapeValidator::class)->validate($shape);

                foreach ($errors as $path => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($path, $message);
                    }
                }
            },
        ];
    }
}
