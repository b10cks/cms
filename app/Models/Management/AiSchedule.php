<?php

namespace App\Models\Management;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A skill run over a saved scope on a cron schedule. Runs act as `created_by`
 * (whoever last saved the schedule) and always produce a changeset.
 *
 * @property string $id
 * @property string $space_id
 * @property string $created_by
 * @property string $name
 * @property string $skill
 * @property array{type: string, filters?: array<string, string>} $scope
 * @property string $cron
 * @property float $max_usd
 * @property bool $enabled
 * @property string|null $paused_reason
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property string|null $last_run_id
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiSchedule extends GlobalModel
{
    use HasUlids;

    /** The creator lost access to the space, its AI settings or the scope. */
    public const PAUSED_ACCESS = 'access_revoked';

    /** The skill was removed or no longer proposes changes. */
    public const PAUSED_SKILL = 'skill_unavailable';

    protected $fillable = [
        'space_id',
        'created_by',
        'name',
        'skill',
        'scope',
        'cron',
        'max_usd',
        'enabled',
        'paused_reason',
        'last_run_at',
        'last_run_id',
    ];

    protected $casts = [
        'scope' => 'array',
        'max_usd' => 'float',
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'last_run_id');
    }
}
