<?php

use App\Mcp\Servers\ManagementServer;
use Laravel\Mcp\Facades\Mcp;

// Streamable HTTP MCP server — authenticate with a Sanctum bearer token.
Mcp::web('/mcp/v1', ManagementServer::class)
    ->middleware(['mgmt', 'auth:sanctum', 'verified'])
    ->name('mcp.mgmt');

// Local stdio server: php artisan mcp:start b10cks
// Authenticates internal calls with services.b10cks_mcp.token (B10CKS_MCP_TOKEN).
Mcp::local('b10cks', ManagementServer::class);
