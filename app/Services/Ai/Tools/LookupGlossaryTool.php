<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\Ai\Knowledge\GlossaryTerm;
use Closure;

/** Glossary terms found in a text, with the required wording for a target locale. */
final readonly class LookupGlossaryTool implements AiToolInterface
{
    /**
     * @param  Closure(): list<GlossaryTerm>  $terms
     */
    public function __construct(private Closure $terms) {}

    public function name(): string
    {
        return 'lookup_glossary';
    }

    public function description(): string
    {
        return 'Finds glossary terms in a text and returns the required wording for the target locale, terms that must stay untranslated and forbidden variants.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'source_locale' => ['type' => 'string'],
                'target_locale' => ['type' => 'string'],
            ],
            'required' => ['text'],
        ];
    }

    public function execute(array $input): mixed
    {
        $target = (string) ($input['target_locale'] ?? '');
        $matches = GlossaryMatcher::for(($this->terms)())->match((string) ($input['text'] ?? ''), $input['source_locale'] ?? null);

        return ['terms' => array_map(static fn (GlossaryTerm $term) => array_filter([
            'term' => $term->term,
            'required' => $target !== '' ? self::translation($term, $target) : null,
            'do_not_translate' => $term->doNotTranslate ?: null,
            'forbidden' => $term->forbidden ?: null,
            'note' => $term->note,
        ], static fn ($value) => $value !== null), $matches)];
    }

    private static function translation(GlossaryTerm $term, string $locale): ?string
    {
        foreach ($term->translations as $key => $value) {
            if (GlossaryMatcher::sameLanguage((string) $key, $locale)) {
                return $value;
            }
        }

        return null;
    }
}
