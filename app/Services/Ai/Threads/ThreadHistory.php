<?php

namespace App\Services\Ai\Threads;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\AiAttachment;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiMessage;
use App\Models\Management\AiRun;

/**
 * What a follow-up run remembers of its thread: earlier prompts and answers
 * as conversation turns, and a ledger of the earlier proposals with their
 * status. Tool results are not replayed; the agent re-reads entries.
 *
 * Both share the `ai.threads.history_tokens` budget (four characters per
 * token). The ledger keeps its newest lines, the oldest turns drop first.
 */
class ThreadHistory
{
    public const CHARS_PER_TOKEN = 4;

    private const MAX_LEDGER_LINES = 100;

    /**
     * @return array{turns: list<array{user: string, assistant: string}>, ledger: list<string>}
     */
    public function for(string $conversationId): array
    {
        $budget = (int) config('ai.threads.history_tokens') * self::CHARS_PER_TOKEN;
        $ledger = $this->ledger($conversationId, $budget);
        $budget -= array_sum(array_map(fn (string $line) => mb_strlen($line) + 1, $ledger));

        $turns = [];
        foreach (array_reverse($this->turns($conversationId)) as $turn) {
            $budget -= mb_strlen($turn['user']) + mb_strlen($turn['assistant']);
            if ($budget < 0) {
                break;
            }
            array_unshift($turns, $turn);
        }

        return ['turns' => $turns, 'ledger' => $ledger];
    }

    /**
     * One turn per earlier run that answered. A run started without a prompt
     * (a skill button) gets a stand-in, so user and assistant alternate.
     *
     * @return list<array{user: string, assistant: string}>
     */
    private function turns(string $conversationId): array
    {
        $skills = AiRun::query()->where('conversation_id', $conversationId)->pluck('skill', 'id');
        $byRun = AiMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereNotNull('run_id')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'run_id', 'role', 'content'])
            ->groupBy('run_id');

        // Only the file names are remembered, never their contents.
        $names = AiAttachment::query()
            ->whereIn('message_id', $byRun->flatten(1)->where('role', 'user')->pluck('id'))
            ->orderBy('id')
            ->get(['message_id', 'name'])
            ->groupBy('message_id')
            ->map(fn ($rows) => '[attached: '.$rows->pluck('name')->implode(', ').']');

        $turns = [];
        foreach ($byRun as $runId => $messages) {
            $answer = $messages->firstWhere('role', 'assistant')?->content;
            if (! \is_string($answer) || trim($answer) === '') {
                continue;
            }

            $skill = $skills[$runId] ?? null;
            $question = $messages->firstWhere('role', 'user');
            $user = trim(implode("\n", array_filter([$question?->content, $names[$question?->id] ?? null])));
            $turns[] = [
                'user' => $user !== '' ? $user : ($skill ? "Run the {$skill} skill." : 'Continue.'),
                'assistant' => $answer,
            ];
        }

        return $turns;
    }

    /**
     * @return list<string> "entry label, field path: status", newest last
     */
    private function ledger(string $conversationId, int $budget): array
    {
        $items = AiChangesetItem::query()
            ->whereHas('changeset.run', fn ($query) => $query->where('conversation_id', $conversationId))
            ->latest('created_at')->latest('id')
            ->limit(self::MAX_LEDGER_LINES)
            ->get(['id', 'operation', 'target_type', 'target_id', 'target_label', 'path', 'status', 'final']);

        $lines = [];
        foreach ($items as $item) {
            $line = json_encode([
                'entry' => $item->target_label ?? "{$item->target_type}:".($item->target_id ?? 'new'),
                'field' => $item->operation === AiChangesetItem::OPERATION_CREATE ? '(new entry)' : $item->path,
                'status' => $item->status === AiChangesetItemStatus::Accepted && $item->final !== null ? 'accepted_edited' : $item->status->value,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

            $budget -= mb_strlen($line) + 1;
            if ($budget < 0) {
                break;
            }
            $lines[] = $line;
        }

        return array_reverse($lines);
    }
}
