<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Skills\SkillManifest;
use Closure;

/** Loads a catalogued skill's full instructions and its file list. */
final readonly class ReadSkillTool implements AiToolInterface
{
    /**
     * @param  Closure(string): ?SkillManifest  $load
     */
    public function __construct(private Closure $load) {}

    public function name(): string
    {
        return 'read_skill';
    }

    public function description(): string
    {
        return 'Loads the full instructions of a skill from the skill catalog, plus the names of its supporting files.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => 'Skill name from the catalog.']],
            'required' => ['name'],
        ];
    }

    public function execute(array $input): mixed
    {
        $skill = ($this->load)((string) ($input['name'] ?? '')) ?? throw new ToolError('No skill with this name in the catalog.');

        return [
            'name' => $skill->name,
            'description' => $skill->description,
            'instructions' => $skill->instructions,
            'files' => $skill->supportingFiles(),
        ];
    }
}
