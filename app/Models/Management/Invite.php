<?php

namespace App\Models\Management;

use App\Http\Resources\Management\InviteResource;
use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\Management\Invite
 *
 * @property string $id
 * @property string|null $space_id
 * @property string|null $team_id
 * @property string $invited_by
 * @property string $role_id
 * @property string|null $message
 * @property string $email
 * @property string|null $language
 * @property string $role
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $inviter
 * @property string|null $make_purified_attribute
 * @property string|null $invitee_id
 * @property-read User|null $invitee
 * @property-read Space|null $space
 * @property-read Team|null $team
 *
 * @method static \Database\Factories\Management\InviteFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite accepted()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite expired()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereInvitedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereLanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invite whereInviteeId($value)
 *
 * @mixin \Eloquent
 */
class Invite extends GlobalModel
{
    use Auditable;
    use Filterable;

    // use BroadcastsModelEvents;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;

    protected $table = 'invites';

    protected ?string $broadcastResource = InviteResource::class;

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    protected function message(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by', 'id');
    }

    public function roleDefinition(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id', 'id');
    }

    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->whereNull('declined_at')->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->whereNull('accepted_at')->whereNull('declined_at')->where('expires_at', '<=', now());
    }

    public function scopeAccepted($query)
    {
        return $query->whereNotNull('accepted_at');
    }

    public function isExpired(): bool
    {
        return ! $this->isAccepted() && ! $this->isDeclined() && $this->expires_at <= now();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isDeclined() && $this->expires_at > now();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * Locale of the invitation mail. An invitee who already has an account
     * reads it in their own language; for a bare address the inviter's pick
     * is all we have, and rows created before the column existed fall back
     * to the app language.
     */
    public function notificationLocale(): string
    {
        return $this->invitee?->preferredLocale()
            ?? $this->language
            ?? config('app.fallback_locale');
    }

    public function getRoleAttribute(): ?string
    {
        return $this->attributes['role_key']
            ?? $this->roleDefinition?->key;
    }
}
