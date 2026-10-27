<?php

namespace App\Services\Ai\Attachments;

use App\Models\Management\AiAttachment;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Knowledge\DocumentTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files attached to a message. They live privately on the `transfers` disk
 * from upload until they are sent; unsent ones are pruned after a day.
 */
class AiAttachments
{
    public function __construct(private readonly DocumentTextExtractor $extractor) {}

    public function store(UploadedFile $file, Space $space, User $user): AiAttachment
    {
        $path = $file->storeAs("ai-attachments/{$space->id}", Str::ulid().'.'.strtolower($file->getClientOriginalExtension()), 'transfers');

        return AiAttachment::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
            'url' => $path,
        ]);
    }

    /**
     * The actor's own, unsent attachments of the space, in the order given.
     *
     * @param  list<string>  $ids
     * @return Collection<int, AiAttachment>
     *
     * @throws ValidationException when an ID is unknown, foreign or already sent
     */
    public function claimable(array $ids, Space $space, User $user): Collection
    {
        $found = AiAttachment::query()
            ->whereIn('id', $ids)
            ->where('space_id', $space->id)
            ->where('user_id', $user->id)
            ->whereNull('message_id')
            ->get()
            ->keyBy('id');

        if ($found->count() !== \count($ids)) {
            throw ValidationException::withMessages(['attachments' => ['One or more attachments are unknown or were already sent.']]);
        }

        return collect($ids)->map(fn (string $id) => $found[$id]);
    }

    /**
     * Image parts for the provider drivers.
     *
     * @param  Collection<int, AiAttachment>  $attachments
     * @return list<array{type: string, mime_type: string, data: string}>
     */
    public function imageParts(Collection $attachments): array
    {
        return $attachments
            ->where('kind', AiAttachment::KIND_IMAGE)
            ->map(fn (AiAttachment $attachment) => [
                'type' => 'image',
                'mime_type' => $attachment->mime_type,
                'data' => base64_encode((string) Storage::disk('transfers')->get($attachment->url)),
            ])
            ->values()
            ->all();
    }

    /**
     * Extracted text of a document, cut to the configured length; null when
     * the file cannot be read.
     */
    public function text(AiAttachment $attachment): ?string
    {
        $disk = Storage::disk('transfers');
        if (! $disk->exists($attachment->url)) {
            return null;
        }

        // The extractor reads from a path, which a remote disk does not have.
        $local = tempnam(sys_get_temp_dir(), 'aiatt');
        file_put_contents($local, $disk->get($attachment->url));

        try {
            return mb_substr($this->extractor->extract($local, pathinfo($attachment->name, PATHINFO_EXTENSION)), 0, (int) config('ai.attachments.document_chars'));
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($local);
        }
    }

    /** Delete unsent attachments older than the configured hours; returns how many. */
    public function pruneUnsent(): int
    {
        $pruned = 0;

        AiAttachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', now()->subHours((int) config('ai.attachments.prune_after_hours')))
            ->each(function (AiAttachment $attachment) use (&$pruned) {
                Storage::disk('transfers')->delete($attachment->url);
                $attachment->delete();
                $pruned++;
            });

        return $pruned;
    }
}
