<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationState;
use App\Enums\AiRunState;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Threads\AiThreads;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The missing facts an operation could not establish, collected once its
 * independent work has finished, and the answers that resume only the outputs
 * they name.
 *
 * A question groups the blocked outputs asking the same thing. Its `id` stays
 * while the wording does; `fingerprint` hashes its exact members, so an answer
 * for the whole group is refused as stale when the membership moved on. A
 * member's `id` also counts the answers its output already got: the same
 * question asked again after an answer is a new member, and a repeated
 * submission of the old one changes nothing.
 *
 * Answers are accepted while the operation is `completed`, which is when
 * nothing else is owed and no run is live. An answer returns it to `running`
 * through the ordinary {@see AiOperations::advance()} path, so the budget,
 * access, pause and cancel rules apply as they do to any batch, and the
 * approved plan, scope and policy stay exactly as they were. Everything lives
 * in `ai_operation_items.detail`, under the operation's lock.
 */
final class OperationQuestions
{
    public const SUBMIT_RECORDED = 'recorded';

    public const SUBMIT_DUPLICATE = 'duplicate';

    public const SUBMIT_STALE = 'stale';

    public function __construct(
        private readonly AiOperations $operations,
        private readonly AiThreads $threads,
    ) {}

    /**
     * The open question groups in the order their first entries sit in the
     * scope, and figures over the whole operation.
     *
     * @return array{groups: list<array{id: string, question: string|null, note: string|null, fingerprint: string, outputs: int, entries: int}>, summary: array{accepting: bool, questions: int, outputs: int, entries: int, answered: int, resuming: int}}
     */
    public function overview(AiOperation $operation): array
    {
        $read = $this->read($operation);
        $members = array_merge([], ...array_column($read['groups'], 'members'));

        return [
            'groups' => array_map(fn (array $group) => [
                'id' => $group['id'],
                'question' => $group['question'],
                'note' => $group['members'][0]['note'],
                'fingerprint' => $group['fingerprint'],
                'outputs' => \count($group['members']),
                'entries' => \count(array_unique(array_column($group['members'], 'item_id'))),
            ], array_values($read['groups'])),
            'summary' => [
                'accepting' => $operation->state === AiOperationState::Completed,
                'questions' => \count($read['groups']),
                'outputs' => \count($members),
                'entries' => \count(array_unique(array_column($members, 'item_id'))),
                'answered' => \count($read['answered']),
                'resuming' => $read['resuming'],
            ],
        ];
    }

    /**
     * Exactly who a question asks about.
     *
     * @return list<array{id: string, item_id: int, target_type: string, target_id: string, label: string|null, part: string, question: string|null, note: string|null}>
     */
    public function members(AiOperation $operation, string $questionId): array
    {
        return $this->read($operation)['groups'][$questionId]['members'] ?? [];
    }

    /**
     * Answers given so far, newest first, one row per submitted answer text.
     *
     * @return list<array{question: string|null, answer: string, answered_by: array{id: string, name: string}|null, answered_at: string, outputs: int, entries: int}>
     */
    public function answered(AiOperation $operation): array
    {
        $rows = [];
        foreach ($this->read($operation)['answered'] as $answer) {
            $key = hash('sha256', implode("\0", [$answer['question'] ?? '', $answer['answer'], $answer['answered_by'], $answer['answered_at']]));
            $rows[$key] ??= [...array_intersect_key($answer, array_flip(['question', 'answer', 'answered_by', 'answered_at'])), 'outputs' => 0, 'items' => []];
            $rows[$key]['outputs']++;
            $rows[$key]['items'][$answer['item_id']] = true;
        }

        $users = User::query()->whereIn('id', array_unique(array_column($rows, 'answered_by')))->get()->keyBy('id');
        usort($rows, fn (array $a, array $b) => $b['answered_at'] <=> $a['answered_at']);

        return array_map(function (array $row) use ($users) {
            $user = $users->get($row['answered_by']);

            return [
                'question' => $row['question'],
                'answer' => $row['answer'],
                'answered_by' => $user !== null ? ['id' => (string) $user->id, 'name' => (string) $user->name] : null,
                'answered_at' => $row['answered_at'],
                'outputs' => $row['outputs'],
                'entries' => \count($row['items']),
            ];
        }, $rows);
    }

    /**
     * Record answers and resume the outputs they name. Each answer takes the
     * whole group at a `fingerprint`, or named `member_ids`, which go first so
     * a group answer covers what is left of the group. Answers that find their
     * outputs answered already or gone change nothing and say so; only a
     * genuinely new answer needs a state that accepts it.
     *
     * @param  list<array{question_id: string, answer: string, fingerprint?: string|null, member_ids?: list<string>|null}>  $answers
     * @return list<array{question_id: string, status: string, recorded: int, duplicate: int, stale: int}> in the order given
     *
     * @throws OperationConflict when new answers meet a state that takes none, or the lock is busy
     */
    public function answer(AiOperation $operation, User $user, array $answers): array
    {
        // The lock AiOperations::locked() takes: nothing advances or cancels the operation meanwhile.
        $lock = Cache::lock("ai-operation:{$operation->id}", 120);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new OperationConflict('The operation is busy. Try again in a moment.', 'busy');
        }

        try {
            [$results, $reopened] = $this->record($operation->refresh(), $user, $answers);
        } finally {
            $lock->release();
        }

        if ($reopened) {
            // Outside the lock: a sync queue runs the batch right here. A crash before this line leaves a running operation the recovery sweep advances.
            $this->operations->advance($operation->refresh());
            $this->threads->changed($operation->space_id, $operation->conversation_id);
        }

        return $results;
    }

    /**
     * @param  list<array{question_id: string, answer: string, fingerprint?: string|null, member_ids?: list<string>|null}>  $answers
     * @return array{0: list<array{question_id: string, status: string, recorded: int, duplicate: int, stale: int}>, 1: bool}
     */
    private function record(AiOperation $operation, User $user, array $answers): array
    {
        $read = $this->read($operation);
        $answeredIds = array_flip(array_column($read['answered'], 'id'));
        $answeredPrints = array_flip(array_filter(array_column($read['answered'], 'fingerprint')));
        $results = array_map(fn (array $answer) => ['question_id' => $answer['question_id'], self::SUBMIT_RECORDED => 0, self::SUBMIT_DUPLICATE => 0, self::SUBMIT_STALE => 0], $answers);
        $claims = [];

        foreach ($answers as $index => $answer) {
            $members = array_column($read['groups'][$answer['question_id']]['members'] ?? [], null, 'id');
            foreach ($answer['member_ids'] ?? [] as $id) {
                $verdict = match (true) {
                    isset($members[$id]) && ! isset($claims[$id]) => self::SUBMIT_RECORDED,
                    isset($claims[$id]), isset($answeredIds[$id]) => self::SUBMIT_DUPLICATE,
                    default => self::SUBMIT_STALE,
                };
                if ($verdict === self::SUBMIT_RECORDED) {
                    $claims[$id] = [...$members[$id], 'answer' => trim($answer['answer']), 'fingerprint' => null];
                }
                $results[$index][$verdict]++;
            }
        }

        foreach ($answers as $index => $answer) {
            $print = $answer['fingerprint'] ?? null;
            if ($print === null) {
                continue;
            }

            $group = $read['groups'][$answer['question_id']] ?? null;
            if ($group === null || ! hash_equals($group['fingerprint'], $print)) {
                $results[$index][isset($answeredPrints[$print]) ? self::SUBMIT_DUPLICATE : self::SUBMIT_STALE]++;

                continue;
            }

            foreach ($group['members'] as $member) {
                if (! isset($claims[$member['id']])) {
                    $claims[$member['id']] = [...$member, 'answer' => trim($answer['answer']), 'fingerprint' => $print];
                    $results[$index][self::SUBMIT_RECORDED]++;
                }
            }
        }

        $results = array_map(fn (array $result) => ['status' => match (true) {
            $result[self::SUBMIT_RECORDED] > 0 => self::SUBMIT_RECORDED,
            $result[self::SUBMIT_DUPLICATE] > 0 => self::SUBMIT_DUPLICATE,
            default => self::SUBMIT_STALE,
        }, ...$result], $results);

        if ($claims === []) {
            return [$results, false];
        }

        $this->ensureAccepting($operation);

        $now = now()->toIso8601String();
        $byItem = [];
        foreach ($claims as $claim) {
            $byItem[$claim['item_id']][$claim['part']] = [
                'id' => $claim['id'],
                'question' => $claim['question'],
                'answer' => $claim['answer'],
                'answered_by' => (string) $user->id,
                'answered_at' => $now,
                'fingerprint' => $claim['fingerprint'],
            ];
        }

        $operation->getConnection()->transaction(function () use ($operation, $byItem) {
            foreach (array_chunk(array_keys($byItem), 500) as $ids) {
                foreach (AiOperationItem::query()->where('operation_id', $operation->id)->whereIn('id', $ids)->get() as $item) {
                    $item->answerBlocked($byItem[$item->id]);
                }
            }

            $operation->forceFill(['state' => AiOperationState::Running, 'finished_at' => null, 'pause_reason' => null, 'pause_requested' => false, 'error' => null])->save();
        });

        return [$results, true];
    }

    /** @throws OperationConflict */
    private function ensureAccepting(AiOperation $operation): void
    {
        if ($operation->state !== AiOperationState::Completed) {
            throw new OperationConflict(
                $operation->state === AiOperationState::Cancelled
                    ? 'This operation was cancelled and takes no more answers.'
                    : 'Answers open once the current work has finished.',
                'questions_closed',
            );
        }

        // Batches are background runs and share the space's limit, as on a resume.
        $running = AiRun::query()
            ->where('space_id', $operation->space_id)
            ->where('mode', AiRun::MODE_BACKGROUND)
            ->where('state', AiRunState::Running->value)
            ->count();

        abort_if($running >= (int) config('ai.runs.max_concurrent_background'), response()->json([
            'message' => 'This space already runs its limit of background AI tasks. Try again when one has finished.',
            'reason' => 'concurrency_limit',
        ], 429));
    }

    /**
     * One pass over the entries holding a question or an answer. Entries come
     * in scope order, parts in their stored order, so ids and fingerprints do
     * not depend on who asks or when.
     *
     * @return array{groups: array<string, array{id: string, question: string|null, fingerprint: string, members: list<array{id: string, item_id: int, target_type: string, target_id: string, label: string|null, part: string, question: string|null, note: string|null}>}>, answered: list<array{id: string, item_id: int, question: string|null, answer: string, answered_by: string, answered_at: string, fingerprint: string|null}>, resuming: int}
     */
    private function read(AiOperation $operation): array
    {
        $groups = [];
        $answered = [];
        $resuming = 0;
        $expected = $operation->expectedParts();

        AiOperationItem::query()
            ->where('operation_id', $operation->id)
            // Skips decoding the entries with neither, which are most of a large scope.
            ->where(fn ($query) => $query->where('detail', 'like', '%"'.ReportsOutcomes::BLOCKED.'"%')->orWhere('detail', 'like', '%"answers"%'))
            ->select(['id', 'target_type', 'target_id', 'label', 'detail'])
            ->lazyById(500)
            ->each(function (AiOperationItem $item) use ($operation, $expected, &$groups, &$answered, &$resuming) {
                $history = (array) ($item->detail['answers'] ?? []);
                foreach ($history as $answers) {
                    foreach ($answers as $answer) {
                        $answered[] = [...$answer, 'item_id' => (int) $item->id];
                    }
                }
                $resuming += \count(array_intersect($item->openParts($expected), array_map('strval', array_keys($history))));

                foreach ($item->blockedParts() as $part => $open) {
                    $question = $open['question'];
                    $key = self::questionId($operation, $question);
                    $groups[$key] ??= ['id' => $key, 'question' => $question, 'fingerprint' => '', 'members' => []];
                    $groups[$key]['members'][] = [
                        'id' => self::memberId($operation, (int) $item->id, (string) $part, \count($history[$part] ?? []), $question),
                        'item_id' => (int) $item->id,
                        'target_type' => $item->target_type,
                        'target_id' => $item->target_id,
                        'label' => $item->label,
                        'part' => (string) $part,
                        'question' => $question,
                        'note' => $open['note'],
                    ];
                }
            });

        foreach ($groups as $key => $group) {
            $groups[$key]['fingerprint'] = hash('sha256', implode("\n", array_column($group['members'], 'id')));
        }

        return ['groups' => $groups, 'answered' => $answered, 'resuming' => $resuming];
    }

    /** Wording that differs only in case or spacing asks the same thing. */
    private static function questionId(AiOperation $operation, ?string $question): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $question)));

        return substr(hash('sha256', "{$operation->id}\0{$normalized}"), 0, 32);
    }

    private static function memberId(AiOperation $operation, int $itemId, string $part, int $round, ?string $question): string
    {
        return substr(hash('sha256', "{$operation->id}\0{$itemId}\0{$part}\0{$round}\0{$question}"), 0, 32);
    }
}
