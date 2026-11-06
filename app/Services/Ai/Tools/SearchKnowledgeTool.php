<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Contracts\ProvidesSources;
use App\Services\Ai\Knowledge\KnowledgeSearch;
use App\Services\Ai\Run\ToolError;

/** Searches the reference documents the run was started with. */
final class SearchKnowledgeTool implements AiToolInterface, ProvidesSources
{
    /** @var list<array<string, mixed>> */
    private array $sources = [];

    /**
     * @param  array<string, string>  $revisions  revision_id => document_id
     * @param  (\Closure(): array<string, array<string, mixed>>|null)|null  $frozenSources  a pinned run's descriptors by revision, read on first use
     */
    public function __construct(
        private readonly KnowledgeSearch $search,
        private readonly array $revisions,
        private readonly ?\Closure $frozenSources = null,
    ) {}

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Searches the space\'s reference documents and returns short excerpts with their source. Cite the document title when you use an excerpt.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Keywords to search for.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of excerpts (1-8).'],
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

        $sources = $this->frozenSources !== null ? ($this->frozenSources)() : null;
        $hits = $this->search->search($query, $this->revisions, max(1, min(8, (int) ($input['limit'] ?? 5))), $sources);
        $unavailable = $this->search->unavailable($this->revisions, $sources);
        $this->sources = array_map(static fn (array $hit) => [
            'document_id' => $hit['document_id'],
            'revision_id' => $hit['revision_id'],
            'title' => $hit['title'],
            'heading' => $hit['heading'],
        ], $hits);

        return ['results' => $hits, ...($unavailable !== [] ? ['unavailable' => $unavailable, 'note' => 'These reference revisions of the approved policy no longer exist, so this search could not read them. Do not treat their absence as evidence.'] : [])];
    }

    public function takeSources(): array
    {
        [$sources, $this->sources] = [$this->sources, []];

        return $sources;
    }
}
