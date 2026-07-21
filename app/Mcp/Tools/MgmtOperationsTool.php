<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\OperationRegistry;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MgmtOperationsTool extends Tool
{
    protected string $name = 'b10cks_mgmt_operations';

    protected string $description = 'List all supported b10cks Management API operations.';

    public function handle(): Response
    {
        return Response::text(json_encode(
            OperationRegistry::listing(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
