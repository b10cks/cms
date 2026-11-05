<?php

namespace App\Http\Requests\Ai;

use App\Services\Ai\Knowledge\Concepts\GlossaryLocales;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One glossary row. Sheet imports validate each row against {@see conceptRules()} too.
 * The writer owns the note's limit, because only it knows the note a row already holds.
 *
 * @requestProperty languages shape=map:@AiGlossaryConceptRowInput Keyed by locale. An omitted language keeps its values, as does an omitted field.
 * @requestProperty remove_languages shape=string[] The only way to remove a language.
 * @requestProperty base shape=object On a change, the row's `keep_exact`, `note` and `languages` as you read them. A change to a field that moved since then is refused with `row_changed`. Without it, the last write wins.
 */
class SaveAiGlossaryConceptRowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...self::conceptRules($this->isMethod('post')),
            'base' => ['sometimes', 'array'],
            'base.keep_exact' => ['sometimes', 'boolean'],
            'base.note' => ['sometimes', 'nullable', 'string'],
            'base.languages' => ['sometimes', 'array'],
            'base.languages.*' => ['array'],
            'base.languages.*.use' => ['sometimes', 'nullable', 'string'],
            'base.languages.*.avoid' => ['sometimes', 'nullable', 'array'],
            'base.languages.*.avoid.*' => ['string'],
            'base.languages.*.also' => ['sometimes', 'nullable', 'array'],
            'base.languages.*.also.*' => ['string'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function conceptRules(bool $creating = false): array
    {
        return [
            'key' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9._:-]{1,120}$/'],
            'keep_exact' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string'],
            'languages' => [$creating ? 'required' : 'sometimes', 'array', $creating ? 'min:1' : 'min:0', self::localeKeys(...)],
            'languages.*' => ['array'],
            'languages.*.use' => ['sometimes', 'nullable', 'string', 'max:255'],
            'languages.*.avoid' => ['sometimes', 'nullable', 'array', 'max:20'],
            'languages.*.avoid.*' => ['string', 'max:255'],
            'languages.*.also' => ['sometimes', 'nullable', 'array', 'max:100'],
            'languages.*.also.*' => ['string', 'max:255'],
            'remove_languages' => ['sometimes', 'array', 'max:100'],
            'remove_languages.*' => ['string', 'max:20'],
        ];
    }

    /** Rule wildcards cannot address the keys of a map. */
    private static function localeKeys(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (array_keys((array) $value) as $locale) {
            if (! GlossaryLocales::isValidCode((string) $locale)) {
                $fail("The language {$locale} is not a valid locale.");
            }
        }
    }
}
