<?php

namespace App\Actions\Ai;

use App\Enums\AiKnowledgeType;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiKnowledgeRevision;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeChunker;
use Illuminate\Support\Facades\DB;

/**
 * Freezes a document's draft into a revision. Reference documents are chunked
 * by heading for retrieval. Runs keep the revision they started with.
 */
class PublishAiKnowledgeDocument
{
    public function __construct(private readonly KnowledgeChunker $chunker) {}

    public function handle(AiKnowledgeDocument $document, User $user): AiKnowledgeRevision
    {
        $markdown = $document->draft;
        $hash = hash('sha256', $markdown);

        return DB::transaction(function () use ($document, $user, $markdown, $hash) {
            $current = $document->publishedRevision;
            if ($current && $current->hash === $hash) {
                return $current;
            }

            $revision = $document->revisions()->create([
                'revision' => (int) $document->revisions()->max('revision') + 1,
                'markdown' => $markdown,
                'hash' => $hash,
                'published_by' => $user->id,
            ]);

            if ($document->type === AiKnowledgeType::Reference) {
                $revision->chunks()->createMany($this->chunker->chunk($markdown));
            }

            $document->forceFill(['published_revision_id' => $revision->id, 'updated_by' => $user->id])->save();

            return $revision;
        });
    }
}
