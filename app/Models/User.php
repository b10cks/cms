<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User\UserSettings;
use App\Models\User\UserSocialLink;
use App\Notifications\User\ResetPasswordNotification;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * App\Models\User
 *
 * @property string $id
 * @property string $firstname
 * @property string $lastname
 * @property string $email
 * @property string $avatar
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string $source
 * @property string $language_iso
 * @property UserSettings|null $settings
 * @property int $login_count
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property bool $is_root
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read mixed $avatar_url
 * @property-read mixed $display_name
 * @property-read mixed $initials
 * @property string|null $make_purified_attribute
 * @property-read mixed $name
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UserSocialLink> $socialLinks
 * @property-read int|null $social_links_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Space> $spaces
 * @property-read int|null $spaces_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $teams
 * @property-read int|null $teams_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User filter(\CodersCantina\Filter\Filter $filter)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatar($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFirstname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsRoot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLanguageIso($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLoginCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 * @mixin \Eloquent
 */
class User extends Authenticatable implements JWTSubject
{
    use Filterable;
    use HasApiTokens;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use Notifiable;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'firstname',
        'lastname',
        'email',
        'password',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_root' => 'boolean',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'settings' => UserSettings::class,
    ];

    public function getJWTIdentifier(): string
    {
        return $this->getRouteKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'space_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function preferredLocale(): ?string
    {
        return $this->settings->languageIso ?? config('app.fallback_locale');
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn() => Str::substr($this->firstname ?? '', 0, 1)
            . Str::substr($this->lastname ?? '', 0, 1));
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn() => $this->firstname . ' ' . $this->lastname);
    }

    public function socialLinks(): HasMany
    {
        return $this->hasMany(UserSocialLink::class, 'user_id', 'id');
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn() => $this->firstname . ' ' . $this->lastname);
    }

    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->avatar) {
                return null;
            }

            return '/storage/' . $this->avatar;
        });
    }
}
