<?php

namespace App\Services\Ai\Skills;

/**
 * A parsed `SKILL.md` (Agent Skills format) plus the optional `b10cks:` block
 * and its supporting files.
 */
final readonly class SkillManifest
{
    public const OUTPUT_TEXT = 'text';

    public const OUTPUT_RICHTEXT = 'richtext';

    public const OUTPUT_JSON = 'json';

    /**
     * @param  list<string>  $surfaces
     * @param  list<string>  $blocks
     * @param  list<string>|null  $tools  null = every tool of the surface
     * @param  array<string, mixed>|null  $schema  JSON schema the final output must match
     * @param  array<string, int|float>  $budget
     * @param  array<string, string>  $files  path => content, including `SKILL.md`
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $instructions,
        public array $surfaces = [],
        public array $blocks = [],
        public ?array $tools = null,
        public string $output = self::OUTPUT_TEXT,
        public ?array $schema = null,
        public ?string $config = null,
        public array $budget = [],
        public array $files = [],
    ) {}

    public function matches(string $surface, ?string $block = null): bool
    {
        if (! \in_array($surface, $this->surfaces, true)) {
            return false;
        }

        return $this->blocks === [] || $block === null || \in_array($block, $this->blocks, true);
    }

    /**
     * @return list<string>
     */
    public function supportingFiles(): array
    {
        return array_values(array_filter(array_keys($this->files), static fn (string $path) => $path !== SkillParser::ENTRY));
    }
}
