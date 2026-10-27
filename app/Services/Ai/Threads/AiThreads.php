<?php

namespace App\Services\Ai\Threads;

use App\Enums\AiThreadKind;
use App\Events\Ai\AiThreadChanged;
use App\Models\Management\AiConversation;
use App\Models\Management\AiRun;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Run\StartRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Finds or creates the thread a run belongs to and tells clients when a
 * thread changed. User-started runs open a chat thread; each schedule and the
 * space's learning suggestions have one standing thread.
 */
class AiThreads
{
    /** Surfaces that apply in place; their threads are quiet. `field:*` counts too. */
    public const QUIET_SURFACES = ['selection', 'translate', 'meta-tags'];

    private const TITLE_LENGTH = 60;

    private const CHIP_TYPES = ['content', 'data_source', 'asset', 'document'];

    public static function isQuiet(string $surface): bool
    {
        return \in_array($surface, self::QUIET_SURFACES, true) || str_starts_with($surface, 'field:');
    }

    /** A new chat thread owned by the run's actor. */
    public function open(StartRun $start): AiConversation
    {
        return AiConversation::create([
            'space_id' => $start->space->id,
            'user_id' => $start->actor->id,
            'kind' => AiThreadKind::Chat,
            'surface' => $start->surface,
            'title' => $this->title($start),
            'quiet' => self::isQuiet($start->surface),
            'context' => $this->chips($start->context),
            'last_activity_at' => now(),
        ]);
    }

    public function forSchedule(AiSchedule $schedule, string $surface): AiConversation
    {
        return AiConversation::query()->firstOrCreate(['schedule_id' => $schedule->id], [
            'space_id' => $schedule->space_id,
            'kind' => AiThreadKind::Schedule,
            'surface' => $surface,
            'title' => $schedule->name,
            'last_activity_at' => now(),
        ]);
    }

    /** The space's suggestions thread; the lock keeps two learning jobs from creating two. */
    public function suggestions(Space $space): AiConversation
    {
        return Cache::lock("ai-suggestions-thread:{$space->id}", 10)->block(5, fn () => AiConversation::query()->firstOrCreate(
            ['space_id' => $space->id, 'kind' => AiThreadKind::Suggestions->value],
            ['surface' => 'learning', 'title' => 'Glossary suggestions', 'last_activity_at' => now()],
        ));
    }

    /**
     * Move the thread's last activity and tell its clients to refetch.
     * Nothing happens for runs and changesets outside a thread.
     */
    public function changed(string $spaceId, ?string $threadId, bool $activity = true): void
    {
        if ($threadId === null) {
            return;
        }

        if ($activity) {
            AiConversation::query()->whereKey($threadId)->update(['last_activity_at' => now()]);
        }

        broadcast(new AiThreadChanged($spaceId, $threadId));
    }

    public function changedByRun(?string $runId): void
    {
        $run = $runId !== null ? AiRun::query()->find($runId, ['id', 'space_id', 'conversation_id']) : null;

        if ($run !== null) {
            $this->changed($run->space_id, $run->conversation_id);
        }
    }

    /** The skill plus the scope, else the first prompt line; no model call. */
    private function title(StartRun $start): string
    {
        $skill = $start->skill ? Str::ucfirst(str_replace('-', ' ', $start->skill->manifest->name)) : null;
        $scope = $this->scopeLabel($start);

        $title = match (true) {
            $skill !== null && $scope !== null => "{$skill}: {$scope}",
            $skill !== null => $skill,
            trim($start->prompt) !== '' => trim((string) strtok(trim($start->prompt), "\n")),
            default => Str::ucfirst(str_replace(['-', ':', '_'], ' ', $start->surface)),
        };

        return Str::limit($title, self::TITLE_LENGTH, '');
    }

    private function scopeLabel(StartRun $start): ?string
    {
        $entries = $start->context['targets']['entries'] ?? null;
        if (\is_array($entries) && $entries !== []) {
            return \count($entries) === 1 ? ((string) ($entries[0]['name'] ?? '') ?: null) : \count($entries).' entries';
        }

        $contentId = $start->context['content_id'] ?? null;

        return \is_string($contentId) ? Content::query()->whereKey($contentId)->value('name') : null;
    }

    /**
     * The chips the composer attached (`context.chips`): type, ID and label
     * only, never the payloads the screen sent.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{type: string, id: string, label: string}>|null
     */
    private function chips(array $context): ?array
    {
        $chips = [];
        foreach ((array) ($context['chips'] ?? []) as $chip) {
            if (\is_array($chip) && \in_array($chip['type'] ?? null, self::CHIP_TYPES, true) && \is_string($chip['id'] ?? null)) {
                $chips[] = ['type' => $chip['type'], 'id' => $chip['id'], 'label' => Str::limit((string) ($chip['label'] ?? ''), self::TITLE_LENGTH, '')];
            }
        }

        return $chips === [] ? null : $chips;
    }
}
