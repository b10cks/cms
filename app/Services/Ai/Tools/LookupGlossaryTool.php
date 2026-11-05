<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Knowledge\GlossaryBrief;
use App\Services\Ai\Knowledge\GlossaryConcept;
use App\Services\Ai\Knowledge\PolicySnapshot;
use Closure;

/** The glossary rows a text activates, with what the agent must write and avoid. */
final readonly class LookupGlossaryTool implements AiToolInterface
{
    /**
     * @param  Closure(): (PolicySnapshot|list<GlossaryConcept>)  $policy
     */
    public function __construct(private Closure $policy) {}

    public function name(): string
    {
        return 'lookup_glossary';
    }

    public function description(): string
    {
        return 'Finds glossary terms in a text and returns for each one a plain instruction, the required wording for the target locale, '
            .'terms that keep their wording exactly (do_not_translate), wording never to use (forbidden, a * at the end covers longer forms) and its note.';
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
        $policy = ($this->policy)();
        $policy = $policy instanceof PolicySnapshot ? $policy : PolicySnapshot::fromConcepts($policy);
        $text = (string) ($input['text'] ?? '');
        $source = $input['source_locale'] ?? null;
        $target = (string) ($input['target_locale'] ?? '');

        return [
            'terms' => array_map(static function (array $hit) use ($source, $target) {
                $rule = GlossaryBrief::rule($hit, $source, $target);

                return array_filter([
                    'term' => $rule['term'],
                    'instruction' => GlossaryBrief::instruction($rule),
                    'avoided' => $rule['avoided'] ?: null,
                    'required' => $rule['write'],
                    'do_not_translate' => $rule['keep'] !== null ? true : null,
                    'forbidden' => $rule['never'] ?: null,
                    'note' => $rule['note'],
                    'concept_id' => $hit['concept']->id,
                ], static fn ($value) => $value !== null);
            }, $policy->activate($text, $source, $target)),
        ];
    }
}
