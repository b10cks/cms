<?php

namespace App\Console\Commands;

use App\Services\Ai\Attachments\AiAttachments;
use Illuminate\Console\Command;

/** Deletes AI attachments that were uploaded but never sent with a message. */
class PruneAiAttachmentsCommand extends Command
{
    protected $signature = 'ai:prune-attachments';

    protected $description = 'Delete unsent AI attachments older than a day';

    public function handle(AiAttachments $attachments): int
    {
        $this->info("{$attachments->pruneUnsent()} unsent attachment(s) pruned.");

        return self::SUCCESS;
    }
}
