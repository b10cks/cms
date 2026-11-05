<?php

namespace App\Services\Ai\Assets;

use App\Services\Ai\Contracts\ProvidesImages;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;

/**
 * Brings images of inspected assets into a run's transcript. Tool results are
 * text, so the images a turn's tools attached follow the tool results as one
 * user message. Every provider call resends the whole transcript, so images
 * of earlier inspections become short text notes once newer ones arrive. The
 * notes keep the asset ID, so {@see delivered()} still finds the delivery.
 */
final class InspectionImages
{
    /**
     * Append the images the turn's tools attached, if any. Call once after
     * all tool results of a turn, before the next provider call.
     */
    public static function append(Transcript $transcript, ToolSet $tools): bool
    {
        $parts = [];

        foreach ($tools->names() as $name) {
            $tool = $tools->get($name);
            if ($tool instanceof ProvidesImages) {
                array_push($parts, ...$tool->takeImages());
            }
        }

        if ($parts === []) {
            return false;
        }

        $transcript->appendUser($parts);

        return true;
    }

    /**
     * Replace the images of all but the latest inspection with a note, so a
     * long run does not pay for every image on every turn.
     */
    public static function compact(Transcript $transcript): Transcript
    {
        $messages = $transcript->toArray();
        $latest = null;

        foreach ($messages as $index => $message) {
            if (self::isInspection($message)) {
                $latest = $index;
            }
        }

        foreach ($messages as $index => $message) {
            if ($index === $latest || ! self::isInspection($message)) {
                continue;
            }

            $messages[$index]['content'] = array_map(fn (array $part) => ($part['type'] ?? null) === 'image' && isset($part['asset_id'])
                ? ['type' => 'text', 'text' => "(The image of asset {$part['asset_id']} was shown earlier; inspect it again to see it.)", 'asset_id' => $part['asset_id'], 'sha256' => $part['sha256'] ?? null]
                : $part, $message['content']);
        }

        return Transcript::fromArray($messages);
    }

    /**
     * Assets whose image reached the model: an inspection message the provider
     * answered, so a later assistant message exists. Tool results and model
     * text never count, and neither do images waiting for the next provider
     * call. Read from the checkpointed transcript, this survives recovery and
     * {@see compact()}.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, array{message: int, sha256: string|null}> the latest delivery by asset ID
     */
    public static function delivered(array $messages): array
    {
        $delivered = [];
        $answered = false;

        for ($index = \count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            $answered = $answered || ($message['role'] ?? null) === 'assistant';

            if (! $answered || ($message['role'] ?? null) !== 'user' || ! \is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as $part) {
                if (\is_array($part) && \is_string($part['asset_id'] ?? null)) {
                    $delivered[$part['asset_id']] ??= ['message' => $index, 'sha256' => $part['sha256'] ?? null];
                }
            }
        }

        return $delivered;
    }

    /** @param  array<string, mixed>  $message */
    private static function isInspection(array $message): bool
    {
        return ($message['role'] ?? null) === 'user'
            && \is_array($message['content'] ?? null)
            && array_any($message['content'], fn ($part) => \is_array($part) && ($part['type'] ?? null) === 'image' && isset($part['asset_id']));
    }
}
