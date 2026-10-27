<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Skills\SkillManifest;
use Closure;

/** Reads one supporting file of a catalogued skill. Files are text; nothing is executed. */
final readonly class ReadSkillFileTool implements AiToolInterface
{
    /**
     * @param  Closure(string): ?SkillManifest  $load
     */
    public function __construct(private Closure $load) {}

    public function name(): string
    {
        return 'read_skill_file';
    }

    public function description(): string
    {
        return 'Reads a supporting text file of a skill, as listed by read_skill.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Skill name.'],
                'path' => ['type' => 'string', 'description' => 'Relative file path.'],
            ],
            'required' => ['name', 'path'],
        ];
    }

    public function execute(array $input): mixed
    {
        $skill = ($this->load)((string) ($input['name'] ?? '')) ?? throw new ToolError('No skill with this name in the catalog.');
        $path = (string) ($input['path'] ?? '');

        if (! \in_array($path, $skill->supportingFiles(), true)) {
            throw new ToolError('The skill has no such file.');
        }

        return ['path' => $path, 'content' => $skill->files[$path]];
    }
}
