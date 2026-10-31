<?php

namespace App\Enums;

use App\Services\Ai\Dto\AiModelDto;

/** How hard a reasoning model thinks before it answers. Unset means {@see self::DEFAULT}. */
enum AiReasoningEffort: string
{
    case Off = 'off';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public const DEFAULT = self::Medium;

    /** The run's level wins over the config's; neither set means the default. */
    public static function resolve(?self $run, ?self $config): self
    {
        return $run ?? $config ?? self::DEFAULT;
    }

    /**
     * The level to send for this model: null when it cannot reason (or is
     * unknown), `low` instead of `off` when it cannot stop reasoning.
     */
    public function forModel(?AiModelDto $model): ?self
    {
        if (! ($model?->supportsReasoning ?? false)) {
            return null;
        }

        return $this === self::Off && $model->reasoningRequired ? self::Low : $this;
    }
}
