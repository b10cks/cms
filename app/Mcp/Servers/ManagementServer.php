<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\ContentModelGuideResource;
use App\Mcp\Tools\ContentModelGuideTool;
use App\Mcp\Tools\MgmtCallTool;
use App\Mcp\Tools\MgmtOperationsTool;
use Laravel\Mcp\Server;

class ManagementServer extends Server
{
    protected string $name = 'b10cks';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MARKDOWN'
        MCP server for the b10cks Management API. Use b10cks_mgmt_operations to
        discover the available operations, then execute them with b10cks_mgmt_call.
        Before designing or creating blocks, read b10cks_content_model_guide.
    MARKDOWN;

    protected array $tools = [
        MgmtOperationsTool::class,
        ContentModelGuideTool::class,
        MgmtCallTool::class,
    ];

    protected array $resources = [
        ContentModelGuideResource::class,
    ];
}
