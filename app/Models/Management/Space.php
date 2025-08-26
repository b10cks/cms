<?php

namespace App\Models\Management;

use App\Casts\Slug;
use App\Http\Resources\Management\SpaceResource;
use App\Models\Traits\Auditable;
use App\Models\Traits\BroadcastsModelEvents;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Models\Management\Space
 *
 * @property string $id
 * @property string $state
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $description
 * @property array<array-key, mixed>|null $settings
 * @property string|null $team_id
 * @property \Illuminate\Support\Carbon|null $content_updated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\SpaceConnection> $connections
 * @property-read int|null $connections_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\SpaceConnection> $defaultConnection
 * @property-read int|null $default_connection_count
 * @property-read mixed $icon_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\Invite> $invites
 * @property-read int|null $invites_count
 * @property string|null $make_purified_attribute
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\Storage> $storages
 * @property-read int|null $storages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 * @property-read \App\Models\Management\Team|null $team
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\Token> $tokens
 * @property-read int|null $tokens_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 * @property-read int|null $users_count
 * @property-read int $rv
 * @method static \Database\Factories\Management\SpaceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereContentUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space withoutTrashed()
 * @mixin \Eloquent
 */
class Space extends GlobalModel
{
    use Auditable;
    use BroadcastsModelEvents;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use Filterable;
    use SoftDeletes;

    protected $table = 'spaces';

    protected ?string $broadcastResource = SpaceResource::class;

    protected $fillable = [
        'state',
        'name',
        'slug',
        'icon',
        'color',
        'description',
        'settings',
    ];

    protected $casts = [
        'slug' => Slug::class,
        'settings' => 'array',
        'content_updated_at' => 'datetime',
    ];

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    protected function iconUrl(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->icon) {
                return null;
            }

            return 'storage/' . $this->icon;
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'space_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class, 'space_id', 'id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'space_id', 'id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class, 'space_id', 'id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(SpaceConnection::class, 'space_id', 'id');
    }

    public function storages(): HasMany
    {
        return $this->hasMany(Storage::class, 'space_id', 'id');
    }

    public function defaultConnection(): HasMany
    {
        return $this->hasMany(SpaceConnection::class, 'space_id', 'id')
            ->where('is_default', true);
    }

    public function getRvAttribute(): int
    {
        return $this->content_updated_at?->getTimestamp() ?? $this->updated_at?->getTimestamp() ?? 0;
    }
}
