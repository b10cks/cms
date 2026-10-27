<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Resources\Management\Ai\ResolvedSkillResource;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Skills\SkillResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListSkillsTool extends AiSpaceTool
{
    protected string $name = 'list_skills';

    protected string $description = 'List the AI skills available in a space: the space, team and built-in skills after shadowing, as the app shows them. Load one with read_skill.';

    public function __construct(private readonly SkillResolver $skills) {}

    protected function run(Space $space, User $user, Request $request): Response
    {
        return Response::text(json_encode(
            ResolvedSkillResource::collection(array_values($this->skills->resolve($space)))->resolve(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
