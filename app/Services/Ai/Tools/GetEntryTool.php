<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use App\Services\Auth\AuthorizationService;
use App\Services\Content\ContentI18nService;
use App\Services\Content\Schema\SchemaField;

/**
 * Reads one entry's current (draft) state, with the version to pin a change to.
 */
class GetEntryTool implements AiToolInterface
{
    public function __construct(
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
        private readonly Space $space,
        private readonly User $actor,
    ) {}

    public function name(): string
    {
        return 'get_entry';
    }

    public function description(): string
    {
        return 'Reads one entry by ID. Content returns its name, slug, block, version_id, the field values of its current draft, '
            .'`fields` (type and whether it is translatable, per root field), `i18n`: the source entry ID when it is a translation, its translations by language and the languages it has none in. '
            .'Data entries return `value` and `dimensions`; assets return their metadata `fields` per locale; '
            .'blocks (by ID or slug) return their name, slug, type, description, icon, color, `schema` (field definitions by key) and `editor` (the page layout).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The entry ID (ULID).'],
                'target_type' => ['type' => 'string', 'enum' => ChangeTargets::PROPOSAL_TYPES, 'description' => 'Defaults to content.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $input): mixed
    {
        $type = (string) ($input['target_type'] ?? AiChangesetItem::TARGET_CONTENT);
        $target = $this->targets->for($type);

        if (! $this->auth->canInSpace($this->actor, $this->space, $target->viewAbility())) {
            throw new ToolError('You do not have access to this information.');
        }

        $snapshot = $target->find((string) ($input['id'] ?? '')) ?? throw new ToolError('No entry with this ID.');
        $model = $snapshot->model;

        if ($model instanceof Content) {
            return [
                'id' => $model->id,
                'name' => $model->name,
                'slug' => $model->slug,
                'full_slug' => $model->full_slug,
                'language' => $model->language_iso,
                'block' => $model->block?->slug,
                'version_id' => $snapshot->versionId,
                'content' => $snapshot->document,
                'fields' => array_map(fn ($field) => [
                    'type' => SchemaField::canonicalizeType(\is_array($field) ? ($field['type'] ?? null) : null),
                    'translatable' => \is_array($field) && (bool) ($field['translatable'] ?? false),
                ], array_filter($model->block?->schema?->toArray() ?? [], 'is_array')),
                'i18n' => $this->i18n($model),
            ];
        }

        if ($model instanceof Block) {
            return ['id' => $model->id, 'target_type' => $type, 'slug' => $model->slug, ...$snapshot->document];
        }

        return ['id' => $model->getKey(), 'target_type' => $type, 'name' => $snapshot->label, ...$snapshot->document];
    }

    /**
     * The entry's language family: translations are written to their own row, never to the source.
     *
     * @return array{source_id: string|null, translations: array<string, string>, missing_languages: list<string>}
     */
    private function i18n(Content $content): array
    {
        $family = app(ContentI18nService::class)->getFamily($content);
        $default = $this->space->settings->getDefaultLanguage();
        $translations = $family->filter(fn (Content $row) => $row->i18n_parent_id !== null)->pluck('id', 'language_iso')->all();

        return [
            'source_id' => $content->i18n_parent_id,
            'translations' => $translations,
            'missing_languages' => array_values(array_diff($this->space->settings->getEnabledLanguages(), [$default, ...array_keys($translations)])),
        ];
    }
}
