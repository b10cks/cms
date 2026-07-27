<?php

namespace App\Models\Management;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasPurifiedAttributes;
use App\Models\User;
use CodersCantina\Filter\Filterable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * App\Models\Management\SpaceBackup
 *
 * @property string $id
 * @property string $space_id
 * @property string|null $created_by
 * @property string $state
 * @property int $progress
 * @property string $name
 * @property string|null $description
 * @property array<array-key, mixed> $recipients
 * @property string|null $password
 * @property string|null $s3_path
 * @property int|null $file_size
 * @property string|null $checksum
 * @property Carbon $expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $make_purified_attribute
 * @property-read User|null $creator
 * @property-read Space $space
 *
 * @method static \Database\Factories\Management\SpaceBackupFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereChecksum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereFileSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereProgress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereS3Path($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereSpaceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpaceBackup withoutTrashed()
 *
 * @mixin \Eloquent
 */
class SpaceBackup extends GlobalModel
{
    use Auditable;
    use Filterable;
    use HasFactory;
    use HasPurifiedAttributes;
    use HasUlids;
    use SoftDeletes;

    protected $table = 'space_backups';

    protected $fillable = [
        'space_id',
        'created_by',
        'state',
        'progress',
        'name',
        'description',
        'recipients',
        'password',
        's3_path',
        'file_size',
        'checksum',
        'expires_at',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'recipients' => 'array',
        // The archive password unlocks a full space dump; it has no business
        // sitting in plaintext in the management database or its backups.
        'password' => 'encrypted',
        'progress' => 'integer',
        'file_size' => 'integer',
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected function name(): Attribute
    {
        return $this->makePurifiedAttribute('removeAll');
    }

    protected function description(): Attribute
    {
        return $this->makePurifiedAttribute('rte');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    public function isExpired(): bool
    {
        return $this->state === 'expired' || $this->expires_at->isPast();
    }

    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }

    public function markAsStarted(): void
    {
        $this->update([
            'state' => 'pending',
            'started_at' => now(),
            'progress' => 0,
        ]);
    }

    /**
     * Maximum lifetime of a presigned backup download link, in minutes.
     *
     * `expires_at` is caller-supplied and says how long the backup is kept,
     * which is not the same question as how long a single link to a full
     * database-and-asset dump should stay valid to anyone who has it.
     */
    private const MAX_DOWNLOAD_URL_MINUTES = 60;

    public function getDownloadUrl(): string
    {
        $expiration = min(
            max((int) now()->diffInMinutes($this->expires_at), 1),
            self::MAX_DOWNLOAD_URL_MINUTES
        );

        return \Storage::disk('transfers')
            ->temporaryUrl($this->s3_path, now()->addMinutes($expiration));
    }

    public function markAsActive(string $s3Path, int $fileSize, string $checksum): void
    {
        $this->update([
            'state' => 'active',
            's3_path' => $s3Path,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'completed_at' => now(),
            'progress' => 100,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'state' => 'failed',
            'error_message' => $errorMessage,
            'failed_at' => now(),
        ]);
    }

    public function markAsExpired(): void
    {
        $this->update(['state' => 'expired']);
    }

    public function updateProgress(int $progress): void
    {
        $this->update(['progress' => min(99, max(0, $progress))]);
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    public function scopeActive($query)
    {
        return $query->where('state', 'active')->where('expires_at', '>=', now());
    }
}
