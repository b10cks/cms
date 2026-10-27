<?php

namespace App\Services\Ai\Skills;

/**
 * The system layer: skills shipped as files under `resources/ai/skills/<name>/`.
 * Read from disk, no sync step. Memoised per instance only.
 */
class BuiltinSkills
{
    /** @var array<string, SkillManifest>|null */
    private ?array $skills = null;

    public function __construct(
        private readonly SkillParser $parser,
        private readonly ?string $path = null,
    ) {}

    /**
     * @return array<string, SkillManifest>
     */
    public function all(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $this->skills = [];
        $root = $this->path ?? resource_path('ai/skills');

        foreach (glob($root.'/*/'.SkillParser::ENTRY) ?: [] as $entry) {
            $dir = \dirname($entry);
            $files = [];

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $files[substr($file->getPathname(), \strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
            }

            $manifest = $this->parser->parse($files);
            $this->skills[$manifest->name] = $manifest;
        }

        ksort($this->skills);

        return $this->skills;
    }

    public function get(string $name): ?SkillManifest
    {
        return $this->all()[$name] ?? null;
    }
}
