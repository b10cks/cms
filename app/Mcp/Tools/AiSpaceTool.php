<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for AI tools that act as the token's user in one space they can view,
 * the same gate the in-app skill catalog uses.
 */
abstract class AiSpaceTool extends Tool
{
    abstract protected function run(Space $space, User $user, Request $request): Response;

    /**
     * @return array<string, mixed>
     */
    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'spaceId' => $schema->string()->description('ID of the space to work in.')->required(),
            ...$this->arguments($schema),
        ];
    }

    public function handle(Request $request, AuthorizationService $auth): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('This MCP request is not authenticated as a user.');
        }

        $space = Space::query()->find((string) $request->get('spaceId'));

        if ($space === null || ! $auth->canInSpace($user, $space, 'space.view')) {
            return Response::error('Space not found.');
        }

        return $this->run($space, $user, $request);
    }
}
