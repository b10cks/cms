<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class SearchKnowledgeTool extends AiToolBridge
{
    protected string $name = 'search_knowledge';

    protected string $description = 'Search the published reference documents that apply to a space and return short excerpts with their source.';

    protected function toolName(): string
    {
        return 'search_knowledge';
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Keywords to search for.')->required(),
            'limit' => $schema->integer()->description('Maximum number of excerpts (1-8).'),
        ];
    }
}
