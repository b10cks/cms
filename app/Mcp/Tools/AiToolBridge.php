<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Run\RunToolFactory;
use App\Services\Ai\Run\ToolCall;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/** Exposes one in-app agent tool unchanged, so MCP clients see what the app's agent sees. */
abstract class AiToolBridge extends AiSpaceTool
{
    abstract protected function toolName(): string;

    public function __construct(private readonly RunToolFactory $tools) {}

    protected function run(Space $space, User $user, Request $request): Response
    {
        $arguments = array_diff_key($request->all(), ['spaceId' => true]);

        return Response::text(
            $this->tools->forSpace($space, $user, [$this->toolName()])->execute(new ToolCall('mcp', $this->toolName(), $arguments)),
        );
    }
}
