<?php

namespace App\Services\Content\Serial;

use App\Models\Space\ContentSerial;
use App\Services\Content\Schema\SchemaField;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Hands out numbers and writes the ledger row that reserves them.
 *
 * Correctness rests on the unique indexes, not on locking: two concurrent
 * creates in the same scope both compute the same next number, one insert wins,
 * the loser recomputes. This keeps the allocator portable across the MySQL,
 * Postgres and SQLite space connections, where a `SELECT … FOR UPDATE` or an
 * advisory lock would not be.
 */
class SerialAllocator
{
    public const int MAX_ATTEMPTS = 6;

    public function __construct(
        protected readonly TemplateRenderer $renderer,
        protected readonly ScopeKeyBuilder $scopeKeys,
    ) {}

    /**
     * The number the next allocation in this scope would draw, without
     * reserving it. Used by the create-dialog preview.
     */
    public function peek(string $scopeKey, string $gapStrategy): int
    {
        if ($gapStrategy !== 'reuse') {
            return (int) ContentSerial::query()->where('scope_key', $scopeKey)->max('number') + 1;
        }

        $taken = ContentSerial::query()
            ->where('scope_key', $scopeKey)
            ->orderBy('number')
            ->pluck('number')
            ->all();

        $expected = 1;

        foreach ($taken as $number) {
            if ((int) $number > $expected) {
                break;
            }

            $expected = (int) $number + 1;
        }

        return $expected;
    }

    /**
     * Render a serial without touching the ledger.
     */
    public function preview(SerialFieldConfig $config, SerialContext $context, string $gapStrategy): string
    {
        $scopeKey = $this->scopeKeys->scopeKey($config->scope, $context);
        $number = $this->peek($scopeKey, $gapStrategy);

        return $this->renderer->render($config->format, $context->withNumber($number));
    }

    /**
     * Reserve a number and persist the rendered value.
     *
     * @throws SerialCollisionException when the value is already taken inside
     *                                  the field's uniqueness scope
     */
    public function allocate(
        SerialFieldConfig $config,
        SchemaField $field,
        SerialContext $context,
        string $contentId,
        string $gapStrategy,
    ): SerialAllocation {
        $scopeKey = $this->scopeKeys->scopeKey($config->scope, $context);
        $uniqueKey = $this->scopeKeys->uniqueKey($config->unique, $config->scope, $context);
        $attempt = 0;

        while (true) {
            $attempt++;
            $number = $this->peek($scopeKey, $gapStrategy);
            $value = $this->renderer->render($config->format, $context->withNumber($number));

            if ($uniqueKey !== null && $this->valueTaken($uniqueKey, $value, $contentId)) {
                throw new SerialCollisionException($config->key, $field->getLabel(), $value);
            }

            try {
                return $this->insert($contentId, $config->key, $scopeKey, $uniqueKey, $number, $value);
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception) || $attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                // Lost a race. If the *value* is now taken the format cannot
                // produce a distinct identifier and retrying is pointless;
                // otherwise another entry took the number, so recompute.
                if ($uniqueKey !== null && $this->valueTaken($uniqueKey, $value, $contentId)) {
                    throw new SerialCollisionException($config->key, $field->getLabel(), $value);
                }
            }
        }
    }

    /**
     * Write a specific number/value pair, used by backfills and re-issues that
     * have already decided what the row should be.
     */
    public function claim(
        string $contentId,
        string $fieldKey,
        string $scopeKey,
        ?string $uniqueKey,
        int $number,
        string $value,
    ): SerialAllocation {
        return $this->insert($contentId, $fieldKey, $scopeKey, $uniqueKey, $number, $value);
    }

    /**
     * Drop the reservation so the number and the value return to the pool.
     */
    public function release(string $contentId, ?string $fieldKey = null): int
    {
        return ContentSerial::query()
            ->where('content_id', $contentId)
            ->when($fieldKey !== null, fn ($query) => $query->where('field_key', $fieldKey))
            ->delete();
    }

    protected function insert(
        string $contentId,
        string $fieldKey,
        string $scopeKey,
        ?string $uniqueKey,
        int $number,
        string $value,
    ): SerialAllocation {
        $serial = new ContentSerial([
            'content_id' => $contentId,
            'field_key' => $fieldKey,
            'scope_key' => $scopeKey,
            'unique_key' => $uniqueKey,
            'number' => $number,
            'value' => $value,
        ]);
        $serial->id = strtolower((string) Str::ulid());

        // Wrapped so a losing insert rolls back to a savepoint instead of
        // poisoning an enclosing transaction — Postgres aborts the whole
        // transaction on a constraint violation, which would take the content
        // create down with it rather than letting the retry proceed.
        $serial->getConnection()->transaction(static function () use ($serial): void {
            $serial->save();
        });

        return new SerialAllocation($fieldKey, $number, $value);
    }

    protected function valueTaken(string $uniqueKey, string $value, string $exceptContentId): bool
    {
        return ContentSerial::query()
            ->where('unique_key', $uniqueKey)
            ->where('value', $value)
            ->where('content_id', '!=', $exceptContentId)
            ->exists();
    }

    protected function isUniqueViolation(QueryException $exception): bool
    {
        if (class_exists(\Illuminate\Database\UniqueConstraintViolationException::class)
            && $exception instanceof \Illuminate\Database\UniqueConstraintViolationException
        ) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate entry')
            || in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
