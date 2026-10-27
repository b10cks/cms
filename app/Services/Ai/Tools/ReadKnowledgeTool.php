<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\AiKnowledgeChunk;
use App\Models\Management\AiKnowledgeRevision;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Contracts\ProvidesSources;
use App\Services\Ai\Run\ToolError;

/** Reads a bounded slice of one reference document at the revision the run started with. */
final class ReadKnowledgeTool implements AiToolInterface, ProvidesSources
{
    public const MAX_CHARS = 8000;

    /** @var list<array<string, mixed>> */
    private array $sources = [];

    /**
     * @param  array<string, string>  $revisions  revision_id => document_id
     */
    public function __construct(private readonly array $revisions) {}

    public function name(): string
    {
        return 'read_knowledge';
    }

    public function description(): string
    {
        return 'Reads a reference document by slug, or the section around a chunk_id from search_knowledge. Long documents are returned in slices; pass offset to continue.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'chunk_id' => ['type' => 'integer'],
                'offset' => ['type' => 'integer', 'description' => 'Character offset for long documents.'],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $revisions = AiKnowledgeRevision::query()->with('document')->whereIn('id', array_keys($this->revisions))->get();

        if (isset($input['chunk_id'])) {
            $chunk = AiKnowledgeChunk::query()
                ->whereIn('revision_id', $revisions->pluck('id'))
                ->find((int) $input['chunk_id']) ?? throw new ToolError('No such excerpt.');
            $revision = $revisions->firstWhere('id', $chunk->revision_id);

            return $this->respond($revision, $chunk->heading_path, $chunk->text, 0, mb_strlen($chunk->text));
        }

        $revision = $revisions->first(fn (AiKnowledgeRevision $revision) => $revision->document?->slug === ($input['slug'] ?? null))
            ?? throw new ToolError('No reference document with this slug.');
        $offset = max(0, (int) ($input['offset'] ?? 0));

        return $this->respond($revision, null, mb_substr($revision->markdown, $offset, self::MAX_CHARS), $offset, mb_strlen($revision->markdown));
    }

    public function takeSources(): array
    {
        [$sources, $this->sources] = [$this->sources, []];

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private function respond(AiKnowledgeRevision $revision, ?string $heading, string $text, int $offset, int $total): array
    {
        $this->sources = [[
            'document_id' => $revision->document_id,
            'revision_id' => $revision->id,
            'title' => $revision->document?->title,
            'heading' => $heading,
        ]];

        return array_filter([
            'title' => $revision->document?->title,
            'slug' => $revision->document?->slug,
            'heading' => $heading,
            'text' => $text,
            'next_offset' => $offset + mb_strlen($text) < $total ? $offset + mb_strlen($text) : null,
        ], static fn ($value) => $value !== null);
    }
}
