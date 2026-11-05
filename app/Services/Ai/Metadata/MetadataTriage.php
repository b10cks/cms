<?php

namespace App\Services\Ai\Metadata;

use App\Enums\AiOperationItemStatus;
use App\Models\Space\AiOperation;
use App\Models\Space\Content;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Operations\OperationTriage;
use App\Services\Content\Schema\SchemaField;
use Illuminate\Support\Collection;

/**
 * Freezes what a metadata plan (`plan.metadata`) owes per entry, from the
 * entry's real block schemas and language: one part per `meta` field at any
 * depth and approved key, `<locale>.<field path>.<key>`. A key with a value is
 * satisfied unless the plan may overwrite it. Entries without a meta field or
 * outside the plan's locales are visible skips, and so is a field without a
 * stable path. Other general plans have no triage.
 */
class MetadataTriage implements OperationTriage
{
    public const NO_STABLE_PATH = 'no_stable_path';

    public function __construct(private readonly MetadataFields $fields) {}

    public function triage(AiOperation $operation, Collection $items): array
    {
        $plan = $operation->approved_plan['metadata'] ?? null;
        if (! \is_array($plan)) {
            return [];
        }

        $ids = $items->pluck('target_id')->all();
        $contents = Content::query()->with(['block', 'current_version'])->whereIn('id', $ids)->get()->keyBy('id');
        $decided = [];

        foreach ($items as $item) {
            $content = $contents->get($item->target_id);
            if ($content instanceof Content) {
                $decided[$item->id] = $this->decide($operation, $content, $plan);
            }
        }

        return $decided;
    }

    /**
     * Meta field keys at the top of a block schema.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function metaFields(array $schema): array
    {
        return array_values(array_filter(array_map('strval', array_keys($schema)), fn (string $key) => \is_array($schema[$key]) && SchemaField::canonicalizeType($schema[$key]['type'] ?? null) === 'meta'));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function decide(AiOperation $operation, Content $content, array $plan): array
    {
        $locale = $content->language_iso ?: $operation->space->settings->getDefaultLanguage();
        if (($plan['locales'] ?? []) !== [] && ! \in_array($locale, $plan['locales'], true)) {
            return ['status' => AiOperationItemStatus::Skipped, 'reason' => 'locale_not_in_plan'];
        }

        $document = $content->current_version?->content ?? [];
        $fields = $this->fields->planned($plan, $document, $content->block?->schema?->toArray() ?? []);
        if ($fields === []) {
            return ['status' => AiOperationItemStatus::Skipped, 'reason' => 'no_meta_field'];
        }

        $expected = [];
        $overwritable = [];
        $parts = [];

        foreach ($fields as ['path' => $path, 'addressable' => $addressable]) {
            $stored = $addressable ? FieldPath::get($document, $path) : null;
            foreach ($plan['keys'] as $key) {
                $part = "{$locale}.{$path}.{$key}";
                $expected[] = $part;
                $filled = \is_array($stored) && \is_string($stored[$key] ?? null) && trim($stored[$key]) !== '';

                if (! $addressable) {
                    $parts[$part] = ['outcome' => ReportsOutcomes::SKIPPED, 'reason' => self::NO_STABLE_PATH];
                } elseif ($filled && \in_array($key, $plan['overwrite_keys'] ?? [], true)) {
                    $overwritable[] = $part;
                } elseif ($filled) {
                    $parts[$part] = ['outcome' => ReportsOutcomes::SATISFIED, 'reason' => 'has_value'];
                }
            }
        }

        return ['expected' => $expected, 'overwritable' => $overwritable, 'parts' => $parts];
    }
}
