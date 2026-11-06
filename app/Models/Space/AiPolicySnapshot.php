<?php

namespace App\Models\Space;

use App\Services\Ai\Knowledge\PolicySnapshot;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * The stored form of a {@see PolicySnapshot}. Immutable:
 * the same content always resolves to the same row.
 *
 * @property string $id
 * @property string $space_id
 * @property string $fingerprint
 * @property int $version
 * @property array<string, mixed> $payload
 */
class AiPolicySnapshot extends SpaceDatabaseModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['space_id', 'fingerprint', 'version', 'payload'];

    protected $casts = ['version' => 'integer', 'payload' => 'array'];

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw new \LogicException('Policy snapshots are immutable.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }
}
