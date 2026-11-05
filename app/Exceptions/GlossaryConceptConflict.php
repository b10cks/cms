<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A concept write that cannot apply. Nothing moves silently: the row stays as it
 * was, and `details` names what is in the way.
 */
final class GlossaryConceptConflict extends \RuntimeException
{
    public const WORDING_TAKEN = 'wording_conflict';

    public const KEY_TAKEN = 'key_conflict';

    public const TERM_REQUIRED = 'term_required';

    public const USE_AVOIDED = 'use_avoided';

    public const LAST_LANGUAGE = 'last_language';

    public const STALE = 'stale_glossary';

    public const ROW_CHANGED = 'row_changed';

    public const LANGUAGE_TAKEN = 'language_conflict';

    public const LANGUAGE_INVALID = 'language_invalid';

    public const NOTE_TOO_LONG = 'note_too_long';

    /**
     * @param  self::*  $reason
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $status = \in_array($this->reason, [self::TERM_REQUIRED, self::USE_AVOIDED, self::LAST_LANGUAGE, self::LANGUAGE_INVALID, self::NOTE_TOO_LONG], true) ? 422 : 409;

        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason, ...$this->details], $status);
    }
}
