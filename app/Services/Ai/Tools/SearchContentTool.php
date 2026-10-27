<?php

namespace App\Services\Ai\Tools;

use App\Actions\Content\TransformContentToSearchable;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use App\Services\Search\SearchService;

/** Full-text search over the space's published entries, as short excerpts with entry IDs for `get_entry`. */
final readonly class SearchContentTool implements AiToolInterface
{
    public const EXCERPT_CHARS = 400;

    public function __construct(
        private SearchService $search,
        private Space $space,
    ) {}

    public function name(): string
    {
        return 'search_content';
    }

    public function description(): string
    {
        return 'Searches the published entries of this space and returns short excerpts with the entry ID, name and path. Load a full entry with get_entry.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Keywords to search for.'],
                'language' => ['type' => 'string', 'description' => 'Language code. Defaults to the space default language.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of entries (1-8).'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): mixed
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            throw new ToolError('Provide a search query.');
        }

        $settings = $this->space->settings;
        $language = \in_array($input['language'] ?? null, $settings->getEnabledLanguages(), true)
            ? $input['language']
            : $settings->getDefaultLanguage();

        $found = $this->search->search($this->space, $query, $language, max(1, min(8, (int) ($input['limit'] ?? 5))));
        $transformer = app(TransformContentToSearchable::class);

        return ['results' => array_map(fn (Content $content) => [
            'id' => $content->id,
            'name' => $content->name,
            'full_slug' => $content->full_slug,
            'block' => $content->block?->slug,
            'language' => $content->language_iso,
            'excerpt' => mb_substr($transformer->execute($content, $this->space), 0, self::EXCERPT_CHARS),
        ], $found['results'])];
    }
}
