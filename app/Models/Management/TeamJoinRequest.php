<?php

namespace App\Models\Management;

use App\Enums\JoinRequestStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string|null $team_domain_id
 * @property JoinRequestStatus $status
 * @property string|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 */
class TeamJoinRequest extends GlobalModel
{
    use HasUlids;

    protected $table = 'team_join_requests';

    protected $fillable = [
        'team_id',
        'user_id',
        'team_domain_id',
        'status',
    ];

    protected $casts = [
        'status' => JoinRequestStatus::class,
        'decided_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(TeamDomain::class, 'team_domain_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
