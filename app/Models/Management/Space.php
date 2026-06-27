<?php

namespace App\Models\Management;

use App\Casts\Slug;
use App\Enums\SearchDriver;
use App\Http\Resources\Management\SpaceResource;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * App\Models\Management\Space
 *
 * @property string $id
 * @property string $state
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $color
 * @property string|null $badge
 * @property string|null $description
 * @property SpaceSettings $settings
 * @property string|null $team_id
 * @property \Illuminate\Support\Carbon|null $content_updated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\SpaceConnection> $connections
 * @property-read int|null $connections_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Management\SpaceConnection> $defaultConnection
 * @property-read int|null $default_connection_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AutomationAction> $automationActions
 * @property-read int|null $automation_actions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Automation> $automations
 * @property-read int|null $automations_count
 * @property-read int $rv
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
 *
 * @method static \Database\Factories\Management\SpaceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereContentUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space whereCreatedAt($value)
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Space withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Space extends GlobalModel
{
    use Auditable;

    use Filterable;
    //    use BroadcastsModelEvents;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'spaces';

    protected ?string $broadcastResource = SpaceResource::class;

    protected $fillable = [
        'state',
        'name',
        'slug',
        'icon',
        'color',
        'badge',
        'description',
        'settings',
    ];

    protected $casts = [
        'slug' => Slug::class,
        'settings' => SpaceSettings::class,
        'content_updated_at' => 'datetime',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    protected function iconUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->icon) {
                return null;
            }

            return 'storage/'.$this->icon;
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'space_user')
            ->withPivot(['role_id'])
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

    public function subscriptionPeriods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class, 'space_id', 'id');
    }

    public function resolveCurrentSubscription(?Collection $subscriptions = null): ?Subscription
    {
        $subscriptions ??= $this->relationLoaded('subscriptions')
            ? $this->subscriptions
            : $this->subscriptions()->with('plan')->latest('created_at')->get();

        return self::pickCurrentSubscription($subscriptions);
    }

    public static function pickCurrentSubscription(Collection $subscriptions): ?Subscription
    {
        return $subscriptions->first(fn (Subscription $subscription) => $subscription->isActive())
            ?? $subscriptions->first();
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

    public function aiConfigs(): HasMany
    {
        return $this->hasMany(SpaceAiConfig::class);
    }

    public function defaultAiConfig(): HasOne
    {
        return $this->hasOne(SpaceAiConfig::class)->where('is_default', true);
    }

    public function aiKeys(): HasMany
    {
        return $this->hasMany(SpaceAiKey::class);
    }

    public function defaultConnection(): HasMany
    {
        return $this->hasMany(SpaceConnection::class, 'space_id', 'id')
            ->where('is_default', true);
    }

    public function automationActions(): HasMany
    {
        return $this->hasMany(AutomationAction::class, 'space_id', 'id');
    }

    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class, 'space_id', 'id');
    }

    public function getRvAttribute(): int
    {
        return $this->content_updated_at?->getTimestamp() ?? $this->updated_at?->getTimestamp() ?? 0;
    }

    public function getSearchDriver(): SearchDriver
    {
        $driverValue = data_get($this->settings, 'search_driver', SearchDriver::MYSQL->value);

        return SearchDriver::from($driverValue);
    }
}
