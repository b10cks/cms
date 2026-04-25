<?php

namespace App\Models\User;

use App\Models\Management\GlobalModel;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\User\UserSocialLink
 *
 * @property string $id
 * @property string $external_id
 * @property string $service
 * @property string|null $token
 * @property string $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static Builder<static>|UserSocialLink filter(\CodersCantina\Filter\Filter $filter)
 * @method static Builder<static>|UserSocialLink newModelQuery()
 * @method static Builder<static>|UserSocialLink newQuery()
 * @method static Builder<static>|UserSocialLink query()
 * @method static Builder<static>|UserSocialLink whereCreatedAt($value)
 * @method static Builder<static>|UserSocialLink whereExternalId($value)
 * @method static Builder<static>|UserSocialLink whereId($value)
 * @method static Builder<static>|UserSocialLink whereService($value)
 * @method static Builder<static>|UserSocialLink whereToken($value)
 * @method static Builder<static>|UserSocialLink whereUpdatedAt($value)
 * @method static Builder<static>|UserSocialLink whereUserId($value)
 *
 * @mixin Eloquent
 */
class UserSocialLink extends GlobalModel
{
    use Filterable;
    use HasFactory;
    use HasUlids;

    public const string SERVICE_GOOGLE = 'google';

    public const string SERVICE_GITHUB = 'github';

    public const array SOCIAL_SERVICES = [
        self::SERVICE_GOOGLE,
        self::SERVICE_GITHUB,
    ];

    protected $table = 'user_social_links';

    protected $fillable = [
        'external_id',
        'service',
        'token',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
