<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class AutomationsGuideResource extends Resource
{
    protected string $name = 'Automations Guide';

    protected string $description = 'Payload schemas, trigger config, content actions, template placeholders, and recipes for b10cks automations — validated against CMS source code.';

    protected string $uri = 'b10cks://automations-guide';

    protected string $mimeType = 'text/plain';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('mcp/automations-guide.md')));
    }
}
