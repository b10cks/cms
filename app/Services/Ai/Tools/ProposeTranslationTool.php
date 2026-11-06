<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\ReportsOutcomes;

/**
 * Writes translated units for one source entry in one locale to its draft. The
 * tool picks the row: the existing translation, or a new translation entry in the
 * source's family. It accepts only the units `get_translation_task` lists, and
 * refuses translations that drop placeholders, tags or link targets.
 */
class ProposeTranslationTool extends ProposalTool implements ReportsOutcomes
{
    use ProposesTranslations;

    public const NAME = 'propose_translation';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Writes translations of one source entry into one language straight to the draft of its translation; nothing is published. Pass the units from get_translation_task by ID with their translated text, '
            .'and the source_version_id and version_id it returned. The tool writes to the right translation entry, or creates a new one when the language has none yet (then pass its translated name). '
            .'The result holds the version_id for your next call. A field a person changed comes back under `conflicts`: it stays open for that person, so do not write it again. The rest is written. '
            .'Only listed units are accepted: fields already translated from an unchanged source are kept. Each unit is one field value (rich text as HTML); keep placeholders, tags and link targets exactly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_id' => ['type' => 'string', 'description' => 'The source entry ID from get_translation_task.'],
                'language' => ['type' => 'string', 'description' => 'Target locale code.'],
                'source_version_id' => ['type' => 'string'],
                'version_id' => ['type' => 'string', 'description' => 'The translation\'s version_id; omit when the language has no translation yet.'],
                'name' => ['type' => 'string', 'description' => 'Name of a new translation entry, in the target language.'],
                'slug' => ['type' => 'string', 'description' => 'Optional slug of a new translation entry; derived from the name when omitted.'],
                'units' => [
                    'type' => 'object',
                    'description' => 'Unit ID => translated text.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['source_id', 'language', 'source_version_id', 'units'],
        ];
    }

    public function execute(array $input): mixed
    {
        return $this->proposeTranslation(
            (string) ($input['source_id'] ?? ''),
            (string) ($input['language'] ?? ''),
            \is_array($input['units'] ?? null) ? $input['units'] : [],
            \is_string($input['name'] ?? null) ? $input['name'] : null,
            \is_string($input['slug'] ?? null) ? $input['slug'] : null,
            \is_string($input['source_version_id'] ?? null) ? $input['source_version_id'] : null,
            \is_string($input['version_id'] ?? null) ? $input['version_id'] : null,
        );
    }
}
