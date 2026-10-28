<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored event of a background run, read back by `GET /runs/{run}/events?after=`.
 *
 * @property int $id
 * @property string $run_id
 * @property int $seq
 * @property string $type
 * @property array<string, mixed> $payload
 */
class AiRunEvent extends SpaceDatabaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = ['run_id', 'seq', 'type', 'payload'];

    protected $casts = [
        'seq' => 'integer',
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'run_id');
    }
}
