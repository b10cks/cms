<?php

namespace App\Models\Space;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single access-analytics event on a public asset share.
 *
 * @property string $id
 * @property string $share_id
 * @property string $event 'view'|'unlock'|'download_package'|'download_asset'
 * @property string|null $asset_id
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read AssetShare $share
 *
 * @mixin \Eloquent
 */
class AssetShareEvent extends SpaceModel
{
    use HasUlids;

    public const string EVENT_VIEW = 'view';

    public const string EVENT_UNLOCK = 'unlock';

    public const string EVENT_DOWNLOAD_PACKAGE = 'download_package';

    public const string EVENT_DOWNLOAD_ASSET = 'download_asset';

    /** The table only carries created_at. */
    public const UPDATED_AT = null;

    protected $table = 'asset_share_events';

    protected $fillable = [
        'share_id',
        'event',
        'asset_id',
        'ip_hash',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(AssetShare::class, 'share_id', 'id');
    }
}
