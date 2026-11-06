<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationKind;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AssetFolder;
use App\Services\Ai\Assets\AssetFieldRules;
use App\Services\Ai\Assets\AssetStructure;
use App\Services\Ai\Changesets\CanonicalJson;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Metadata\MetadataFields;
use App\Services\Ai\Metadata\MetadataOutputs;
use App\Services\Ai\Translation\TranslationPlan;

/**
 * Validates and normalizes an operation plan. Every kind takes `summary`,
 * `rules` and `options` (domain settings, scalars or lists of scalars).
 *
 * - translation: `locales` (enabled, not the default language) and
 *   `include`, the kinds of work to do. Batches freeze both as
 *   `setup.translation` for the translation tools (TranslationPlan). Optional
 *   `metadata`, shaped as below, links metadata generation to the translation:
 *   each target locale then also owes those keys, written from that locale's
 *   translated draft. `locales` narrows the plan's own locales.
 * - assets: `permissions` (overwrite, remove_tags, move; all off by default),
 *   `overwrite_fields` (the field keys an overwrite may replace; required
 *   with `overwrite`), `languages` (metadata language keys to write; empty
 *   writes all) and `structure` with planned `folders` and `tags`, each
 *   justified by a reason and examples from the frozen scope, named unlike
 *   any existing folder or tag. Planned folders sit under a folder of the
 *   scope or under another planned folder, and need `move`. Optional
 *   `outputs` {fields, tags} names what each asset owes: field keys per
 *   language and whether tags are requested. Without it coverage stays
 *   explicitly unverified.
 * - general: optional `metadata` {keys, overwrite_keys, fields, locales} turns
 *   a content operation into metadata generation: `keys` are the meta keys to
 *   write, `overwrite_keys` the ones that may replace a stored value (none by
 *   default), `fields` limits it to meta fields of the scope's blocks (a root
 *   field by key, a nested one as `<block slug>.<key>`), and `locales` to
 *   entries in these languages.
 *
 * Both optional keys appear in the normalized plan only when given, so the
 * hash of a plan saved without them does not change.
 *
 * The approved plan is what tools enforce through {@see OperationContext};
 * nothing here depends on the model following its instructions.
 */
final class OperationPlan
{
    public const TRANSLATION_INCLUDE = TranslationPlan::KINDS;

    public const ASSET_PERMISSIONS = ['overwrite', 'remove_tags', 'move'];

    private const MAX_RULES = 50;

    private const MAX_STRUCTURE = 100;

    private const MAX_EXAMPLES = 5;

    private const MAX_OPTIONS = 50;

    /**
     * @param  array<string, mixed>  $plan
     * @return array{plan: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(AiOperation $operation, array $plan): array
    {
        $errors = [];
        $known = ['summary', 'rules', 'options', ...match ($operation->kind) {
            AiOperationKind::Translation => ['locales', 'include', 'metadata'],
            AiOperationKind::Assets => ['permissions', 'overwrite_fields', 'languages', 'structure', 'outputs'],
            AiOperationKind::General => ['metadata'],
        }];

        foreach (array_diff(array_keys($plan), $known) as $key) {
            $errors[(string) $key] = 'Unknown plan key for a '.$operation->kind->value.' operation.';
        }

        $summary = trim((string) (\is_string($plan['summary'] ?? null) ? $plan['summary'] : ''));
        if ($summary === '' || mb_strlen($summary) > 5000) {
            $errors['summary'] = 'Describe the plan in up to 5000 characters.';
        }

        $rules = self::strings($plan['rules'] ?? [], 500);
        if ($rules === null || \count($rules) > self::MAX_RULES) {
            $errors['rules'] = 'Rules are a list of up to '.self::MAX_RULES.' short texts.';
        }

        $options = $plan['options'] ?? [];
        if (! \is_array($options) || \count($options) > self::MAX_OPTIONS || array_is_list($options) && $options !== []
            || array_filter($options, fn ($value) => ! self::isScalarOrScalars($value)) !== []) {
            $errors['options'] = 'Options are up to '.self::MAX_OPTIONS.' named values.';
        }

        $normalized = ['summary' => $summary, 'rules' => $rules ?? [], 'options' => \is_array($options) ? $options : []];

        $normalized += match ($operation->kind) {
            AiOperationKind::Translation => self::translation($operation, $plan, $errors),
            AiOperationKind::Assets => self::assets($operation, $plan, $errors),
            AiOperationKind::General => self::metadata($operation, $plan, $operation->space->settings->getEnabledLanguages(), $errors),
        };

        return ['plan' => $normalized, 'errors' => $errors];
    }

    public static function hash(array $plan): string
    {
        return hash('sha256', CanonicalJson::encode($plan));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function translation(AiOperation $operation, array $plan, array &$errors): array
    {
        $settings = $operation->space->settings;
        $allowed = array_values(array_diff($settings->getEnabledLanguages(), [$settings->getDefaultLanguage()]));
        $locales = array_values(array_unique(self::strings($plan['locales'] ?? null, 20) ?? []));
        $include = array_values(array_unique(self::strings($plan['include'] ?? null, 40) ?? []));

        if ($locales === [] || array_diff($locales, $allowed) !== []) {
            $errors['locales'] = 'Pick target locales from the space languages: '.implode(', ', $allowed).'.';
        }
        if ($include === [] || array_diff($include, self::TRANSLATION_INCLUDE) !== []) {
            $errors['include'] = 'Pick the kinds of work from: '.implode(', ', self::TRANSLATION_INCLUDE).'.';
        }

        return ['locales' => $locales, 'include' => $include, ...self::metadata($operation, $plan, $locales, $errors)];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function assets(AiOperation $operation, array $plan, array &$errors): array
    {
        $given = \is_array($plan['permissions'] ?? null) ? $plan['permissions'] : [];
        if (array_diff(array_keys($given), self::ASSET_PERMISSIONS) !== [] || array_filter($given, fn ($value) => ! \is_bool($value)) !== []) {
            $errors['permissions'] = 'Permissions are true or false for: '.implode(', ', self::ASSET_PERMISSIONS).'.';
        }
        $permissions = array_map(fn (string $name) => ($given[$name] ?? false) === true, array_combine(self::ASSET_PERMISSIONS, self::ASSET_PERMISSIONS));

        $rules = app(AssetFieldRules::class);
        $scopeFolders = self::scopeFolderIds($operation);
        $knownFields = array_values(array_unique(array_merge(...array_map(
            fn (?string $folder) => array_column($rules->definitions($operation->space, $folder), 'key'),
            [null, ...$scopeFolders],
        ))));
        $overwrite = self::strings($plan['overwrite_fields'] ?? [], 100);
        if ($overwrite === null || array_diff($overwrite, $knownFields) !== [] || ($overwrite !== []) !== $permissions['overwrite']) {
            $errors['overwrite_fields'] = 'With overwrite, name the fields it may replace, from: '.implode(', ', $knownFields).'.';
        }
        $knownLanguages = $rules->languages($operation->space);
        $languages = self::strings($plan['languages'] ?? [], 20);
        if ($languages === null || array_diff($languages, $knownLanguages) !== []) {
            $errors['languages'] = 'Languages are metadata language keys: '.implode(', ', $knownLanguages).'.';
        }

        $structure = \is_array($plan['structure'] ?? null) ? $plan['structure'] : [];
        $folders = [];
        $tags = [];
        $examples = self::frozenIds($operation);

        foreach (array_values(\is_array($structure['folders'] ?? null) ? $structure['folders'] : []) as $i => $folder) {
            $key = \is_array($folder) ? trim((string) ($folder['key'] ?? '')) : '';
            $name = \is_array($folder) ? trim((string) ($folder['name'] ?? '')) : '';
            if ($key === '' || $name === '' || isset($folders[$key])) {
                $errors["structure.folders.{$i}"] = 'A planned folder needs a unique key and a name.';

                continue;
            }
            $folders[$key] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 120),
                'parent_id' => \is_string($folder['parent_id'] ?? null) ? $folder['parent_id'] : null,
                'parent_key' => \is_string($folder['parent_key'] ?? null) ? $folder['parent_key'] : null,
                'reason' => mb_substr(trim((string) ($folder['reason'] ?? '')), 0, 500),
                'examples' => array_slice(self::strings($folder['examples'] ?? [], 40) ?? [], 0, self::MAX_EXAMPLES),
            ];
        }

        $library = app(AssetStructure::class);
        foreach ($folders as $key => $folder) {
            if ($folder['parent_key'] === null && $existing = $library->sibling($folder['parent_id'], $folder['name'])) {
                $errors["structure.folders.{$key}.name"] = "The folder \"{$library->path($existing->id)}\" already exists ({$existing->id}); move assets there instead.";
            }
            $parentOk = $folder['parent_key'] !== null
                ? isset($folders[$folder['parent_key']]) && $folder['parent_key'] !== $key
                : $folder['parent_id'] !== null && \in_array($folder['parent_id'], $scopeFolders, true);
            if (! $parentOk) {
                $errors["structure.folders.{$key}.parent"] = 'A planned folder sits under a folder of the scope (parent_id) or another planned folder (parent_key).';
            }
            if ($folder['reason'] === '' || $folder['examples'] === [] || array_diff($folder['examples'], $examples) !== []) {
                $errors["structure.folders.{$key}.examples"] = 'Justify a planned folder with a reason and example assets from the scope.';
            }
        }

        foreach (array_values(\is_array($structure['tags'] ?? null) ? $structure['tags'] : []) as $i => $tag) {
            $name = \is_array($tag) ? trim((string) ($tag['name'] ?? '')) : '';
            $reason = \is_array($tag) ? mb_substr(trim((string) ($tag['reason'] ?? '')), 0, 500) : '';
            $tagExamples = \is_array($tag) ? array_slice(self::strings($tag['examples'] ?? [], 40) ?? [], 0, self::MAX_EXAMPLES) : [];
            if ($name === '' || isset($tags[mb_strtolower($name)]) || $reason === '' || $tagExamples === [] || array_diff($tagExamples, $examples) !== []) {
                $errors["structure.tags.{$i}"] = 'A planned tag needs a unique name, a reason and example assets from the scope.';

                continue;
            }
            if ($existing = $library->tagNamed($name)) {
                $errors["structure.tags.{$i}"] = "The tag \"{$existing->name}\" already exists ({$existing->id}); add it instead.";
            }
            $tags[mb_strtolower($name)] = ['name' => mb_substr($name, 0, 120), 'reason' => $reason, 'examples' => $tagExamples];
        }

        if (\count($folders) + \count($tags) > self::MAX_STRUCTURE) {
            $errors['structure'] = 'Plan at most '.self::MAX_STRUCTURE.' folders and tags.';
        }
        if ($folders !== [] && ! $permissions['move']) {
            $errors['permissions.move'] = 'Planned folders need the move permission.';
        }

        return [
            'permissions' => $permissions,
            'overwrite_fields' => array_values(array_unique($overwrite ?? [])),
            'languages' => array_values(array_unique($languages ?? [])),
            'structure' => ['folders' => array_values($folders), 'tags' => array_values($tags)],
            ...self::assetOutputs($plan, $knownFields, $permissions, $tags, $errors),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $knownFields
     * @param  array<string, bool>  $permissions
     * @param  array<string, mixed>  $plannedTags
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function assetOutputs(array $plan, array $knownFields, array $permissions, array $plannedTags, array &$errors): array
    {
        if (! \array_key_exists('outputs', $plan)) {
            return [];
        }

        $given = \is_array($plan['outputs']) ? $plan['outputs'] : [];
        $fields = array_values(array_unique(self::strings($given['fields'] ?? [], 100) ?? ['']));
        $tags = ($given['tags'] ?? false) === true;

        if (array_diff(array_keys($given), ['fields', 'tags']) !== [] || array_diff($fields, $knownFields) !== [] || ! \is_bool($given['tags'] ?? false)) {
            $errors['outputs'] = 'Outputs are `fields`, from: '.implode(', ', $knownFields).', and `tags`, true or false.';
        } elseif ($fields === [] && ! $tags) {
            $errors['outputs'] = 'Name the fields or tags each asset owes.';
        } elseif (! $tags && ($permissions['remove_tags'] || $plannedTags !== [])) {
            $errors['outputs'] = 'Removing or planning tags needs `tags` in the outputs.';
        }

        return ['outputs' => ['fields' => $fields, 'tags' => $tags]];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $languages  the languages the plan may name
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private static function metadata(AiOperation $operation, array $plan, array $languages, array &$errors): array
    {
        if (! \array_key_exists('metadata', $plan)) {
            return [];
        }

        $given = \is_array($plan['metadata']) ? $plan['metadata'] : [];
        $keys = array_values(array_unique(self::strings($given['keys'] ?? null, 40) ?? []));
        $overwrite = array_values(array_unique(self::strings($given['overwrite_keys'] ?? [], 40) ?? ['']));
        $fields = array_values(array_unique(self::strings($given['fields'] ?? [], 100) ?? ['']));
        $locales = array_values(array_unique(self::strings($given['locales'] ?? [], 20) ?? ['']));

        if (($operation->scope['type'] ?? null) !== AiChangesetItem::TARGET_CONTENT) {
            $errors['metadata'] = 'Metadata plans work on content entries.';
        }
        if (array_diff(array_keys($given), ['keys', 'overwrite_keys', 'fields', 'locales']) !== []) {
            $errors['metadata'] = 'Metadata takes keys, overwrite_keys, fields and locales.';
        }
        if ($keys === [] || array_diff($keys, MetadataOutputs::KEYS) !== []) {
            $errors['metadata.keys'] = 'Pick the keys to write from: '.implode(', ', MetadataOutputs::KEYS).'.';
        }
        if (array_diff($overwrite, $keys) !== []) {
            $errors['metadata.overwrite_keys'] = 'Overwrite keys must be among the keys to write.';
        }
        $known = self::scopeMetaFields($operation);
        if (array_diff($fields, $known) !== []) {
            $errors['metadata.fields'] = 'Fields are meta fields of the blocks in this scope: '.(implode(', ', $known) ?: 'none').'.';
        }
        if (array_diff($locales, $languages) !== []) {
            $errors['metadata.locales'] = 'Locales are these languages of the plan: '.implode(', ', $languages).'.';
        }

        return ['metadata' => ['keys' => $keys, 'overwrite_keys' => $overwrite, 'fields' => $fields, 'locales' => $locales]];
    }

    /**
     * Meta fields the frozen scope's blocks use, nested ones as `<block slug>.<key>`.
     *
     * @return list<string>
     */
    private static function scopeMetaFields(AiOperation $operation): array
    {
        return MetadataFields::refs(AiOperationItem::query()->where('operation_id', $operation->id)->whereNotNull('group')->distinct()->pluck('group')->all());
    }

    /** @return list<string> */
    private static function frozenIds(AiOperation $operation): array
    {
        return AiOperationItem::query()->where('operation_id', $operation->id)->pluck('target_id')->all();
    }

    /**
     * The scope's root folder and its descendants; planned folders hang off these.
     *
     * @return list<string>
     */
    private static function scopeFolderIds(AiOperation $operation): array
    {
        $root = $operation->scope['root_id'] ?? null;
        if (! \is_string($root)) {
            return AssetFolder::query()->pluck('id')->all();
        }

        return ($operation->scope['recursive'] ?? true)
            ? app(EntryScope::class)->folderTree($root)
            : [$root];
    }

    /** @return list<string>|null null when the value is not a list of strings */
    private static function strings(mixed $value, int $maxLength): ?array
    {
        if (! \is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (! \is_string($item) || trim($item) === '' || mb_strlen($item) > $maxLength) {
                return null;
            }
            $strings[] = trim($item);
        }

        return $strings;
    }

    private static function isScalarOrScalars(mixed $value): bool
    {
        if (\is_array($value)) {
            return array_is_list($value) && \count($value) <= 100 && array_filter($value, fn ($item) => ! \is_scalar($item)) === [];
        }

        return \is_scalar($value) || $value === null;
    }
}
