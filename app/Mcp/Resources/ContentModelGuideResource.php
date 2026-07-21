<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ContentModelGuideResource extends Resource
{
    protected string $name = 'Content Modeling Guide';

    protected string $description = 'Best practices, field types, block type patterns, and canonical examples for b10cks content modeling — validated against CMS source code.';

    protected string $uri = 'b10cks://content-model-guide';

    protected string $mimeType = 'text/plain';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('mcp/content-model-guide.md')));
    }
}
