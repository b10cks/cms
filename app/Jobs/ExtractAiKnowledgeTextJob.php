<?php

namespace App\Jobs;

use App\Models\Management\AiKnowledgeDocument;
use App\Services\Ai\Knowledge\DocumentTextExtractor;
use Illuminate\Support\Facades\Storage;

/**
 * Extracts text from an uploaded PDF or DOCX into the document's draft. The
 * editor reviews and publishes it like any other draft.
 */
class ExtractAiKnowledgeTextJob extends QueuedJob
{
    public $tries = 1;

    public $timeout = 300;

    public function __construct(public string $documentId)
    {
        $this->onQueue('heavy');
    }

    protected function execute(): void
    {
        $document = AiKnowledgeDocument::query()->find($this->documentId);

        if (! $document?->source_path) {
            return;
        }

        // The transfers disk is shared between web and workers (S3 in hosted
        // setups), so the upload is copied to a local temp file first.
        $local = tempnam(sys_get_temp_dir(), 'ai-knowledge-');
        $source = Storage::disk('transfers')->readStream($document->source_path);
        $target = fopen($local, 'wb');

        try {
            stream_copy_to_stream($source, $target);
            fclose($target);

            $text = app(DocumentTextExtractor::class)->extract(
                $local,
                pathinfo((string) $document->source_name, PATHINFO_EXTENSION),
            );
        } finally {
            \is_resource($source) && fclose($source);
            @unlink($local);
        }

        $document->forceFill(['draft' => $text, 'extraction_status' => 'done'])->save();
    }

    protected function handleFailure(\Throwable $e): void
    {
        AiKnowledgeDocument::query()->whereKey($this->documentId)->update(['extraction_status' => 'failed']);
    }
}
