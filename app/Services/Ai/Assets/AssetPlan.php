<?php

namespace App\Services\Ai\Assets;

/**
 * What an approved asset operation may change, read from the run's
 * `setup.plan.assets`. Without a plan a run only fills empty metadata and
 * adds existing or newly proposed tags: overwriting fields, removing tags and
 * moving assets each need the editor's explicit approval in the plan.
 */
final readonly class AssetPlan
{
    /** Ledger part of the tag output; field parts are `<language>.<field>`. */
    public const PART_TAGS = 'tags';

    /** Ledger part of the placement decision under a plan that may move. */
    public const PART_FOLDER = 'folder';

    /**
     * @param  list<string>|null  $languages  language keys to write; null = all
     * @param  list<string>  $overwrite  field keys whose non-empty values may be replaced
     * @param  list<string>  $approvedStructure  IDs of folder and tag proposals the editor approved with the plan
     */
    public function __construct(
        public ?array $languages = null,
        public array $overwrite = [],
        public bool $removeTags = false,
        public bool $move = false,
        public bool $newStructure = true,
        public array $approvedStructure = [],
    ) {}

    /** @param  array<string, mixed>  $setup */
    public static function fromSetup(array $setup): self
    {
        $plan = $setup['plan']['assets'] ?? null;

        if (! \is_array($plan)) {
            return new self;
        }

        return new self(
            languages: \is_array($plan['languages'] ?? null) ? self::strings($plan['languages']) : null,
            overwrite: self::strings($plan['overwrite'] ?? []),
            removeTags: (bool) ($plan['remove_tags'] ?? false),
            move: (bool) ($plan['move'] ?? false),
            newStructure: (bool) ($plan['new_structure'] ?? true),
            approvedStructure: self::strings($plan['approved_structure'] ?? []),
        );
    }

    /**
     * The plan of an operation batch: what the editor approved, with the
     * planned folders and tags as the structure proposals they became.
     * Nothing beyond the approved structure may be proposed.
     *
     * @param  array<string, mixed>  $approved  the operation's approved plan
     * @param  list<string>  $structure  IDs of the planned structure proposals
     */
    public static function forOperation(array $approved, array $structure): self
    {
        $permissions = (array) ($approved['permissions'] ?? []);
        $languages = self::strings($approved['languages'] ?? []);

        return new self(
            languages: $languages === [] ? null : $languages,
            overwrite: ($permissions['overwrite'] ?? false) === true ? self::strings($approved['overwrite_fields'] ?? []) : [],
            removeTags: ($permissions['remove_tags'] ?? false) === true,
            move: ($permissions['move'] ?? false) === true,
            newStructure: false,
            approvedStructure: $structure,
        );
    }

    /**
     * Validation rules for the plan an editor approves, keyed under `$prefix`.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'plan.assets'): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.languages" => ['nullable', 'array'],
            "{$prefix}.languages.*" => ['string', 'max:20'],
            "{$prefix}.overwrite" => ['array'],
            "{$prefix}.overwrite.*" => ['string', 'max:100'],
            "{$prefix}.remove_tags" => ['boolean'],
            "{$prefix}.move" => ['boolean'],
            "{$prefix}.new_structure" => ['boolean'],
            "{$prefix}.approved_structure" => ['array', 'max:500'],
            "{$prefix}.approved_structure.*" => ['string', 'max:40'],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'languages' => $this->languages,
            'overwrite' => $this->overwrite,
            'remove_tags' => $this->removeTags,
            'move' => $this->move,
            'new_structure' => $this->newStructure,
            'approved_structure' => $this->approvedStructure,
        ];
    }

    public function writesLanguage(string $language): bool
    {
        return $this->languages === null || \in_array($language, $this->languages, true);
    }

    public function overwrites(string $field): bool
    {
        return \in_array($field, $this->overwrite, true);
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, fn ($value) => \is_string($value) && $value !== '')) : [];
    }
}
