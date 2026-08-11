<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AutomationsGuideTool extends Tool
{
    protected string $name = 'b10cks_automations_guide';

    protected string $description = 'Return the b10cks automations guide: action payload schemas (webhook/email/void, secrets), trigger types with their config, conditions and template placeholders, content actions (manual automations offered in the content tree, block-type restriction, triggering with content_id), and worked recipes such as a CDN cache-clear content action. Read this before creating automation actions or automations.';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('mcp/automations-guide.md')));
    }
}
