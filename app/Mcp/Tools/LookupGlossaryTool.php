<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

class LookupGlossaryTool extends AiToolBridge
{
    protected string $name = 'lookup_glossary';

    protected string $description = 'Find glossary terms in a text and return the required wording for a target locale, terms to keep untranslated and forbidden variants.';

    protected function toolName(): string
    {
        return 'lookup_glossary';
    }

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('Text to scan for glossary terms.')->required(),
            'source_locale' => $schema->string(),
            'target_locale' => $schema->string(),
        ];
    }
}
