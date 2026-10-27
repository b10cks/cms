<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class ReadSkillTool extends AiToolBridge
{
    protected string $name = 'read_skill';

    protected string $description = 'Load the full instructions of a skill from list_skills, plus the names of its supporting files.';

    protected function toolName(): string
    {
        return 'read_skill';
    }

    protected function arguments(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->description('Skill name from list_skills.')->required()];
    }
}
