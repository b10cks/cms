<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ContentModelGuideTool extends Tool
{
    protected string $name = 'b10cks_content_model_guide';

    protected string $description = 'Return the b10cks content modeling guide: block types (root/nestable/single), atomic design tag hierarchy (Atom/Molecule/Organism/Navigation/FormField/Drawer/Listable), all field types with configuration options, editor layout patterns, and canonical block examples derived from a production project. Read this before designing or creating blocks.';

    public function handle(): Response
    {
        return Response::text(file_get_contents(resource_path('mcp/content-model-guide.md')));
    }
}
