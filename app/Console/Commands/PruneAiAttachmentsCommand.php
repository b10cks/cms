<?php

namespace App\Console\Commands;

use App\Models\Management\Space;
use App\Services\Ai\Attachments\AiAttachments;
use App\Support\SpaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Deletes AI attachments that were uploaded but never sent with a message, space by space. */
class PruneAiAttachmentsCommand extends Command
{
    protected $signature = 'ai:prune-attachments';

    protected $description = 'Delete unsent AI attachments older than a day';

    public function handle(AiAttachments $attachments): int
    {
        $pruned = 0;
        $failed = 0;

        Space::query()->with('defaultConnection')->orderBy('id')->chunkById(100, function ($spaces) use ($attachments, &$pruned, &$failed) {
            foreach ($spaces as $space) {
                $restore = SpaceContext::enter($space);

                try {
                    $pruned += $attachments->pruneUnsent();
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Failed to prune AI attachments of a space', ['space' => $space->id, 'error' => $e->getMessage()]);
                } finally {
                    $restore();
                }
            }
        });

        $this->info("{$pruned} unsent attachment(s) pruned; {$failed} space(s) failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
