<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class SearchContentTool extends AiToolBridge
{
    protected string $name = 'search_content';

    protected string $description = 'Search the published entries of a space and return short excerpts with entry ID, name and path. Needs the content.view ability.';

    protected function toolName(): string
    {
        return 'search_content';
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Keywords to search for.')->required(),
            'language' => $schema->string()->description('Language code. Defaults to the space default language.'),
            'limit' => $schema->integer()->description('Maximum number of entries (1-8).'),
        ];
    }
}
