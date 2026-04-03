<?php

namespace App\Models\Traits;

use App\Services\Audit\AuditActor;
use App\Services\Audit\SpaceAuditLogService;
use Illuminate\Support\Facades\Auth;

/**
 * Adds space-scoped audit logging to space models.
 *
 * Auto-fires on created/updated/deleted model events.
 * For lifecycle operations (publish, commit, etc.) call auditSpaceEvent() explicitly
 * and use withoutAudit() before save() to suppress the redundant generic updated row.
 */
trait SpaceAuditable
{
    /**
     * Suppress the next automatic audit event on this model instance.
     */
    private bool $skipNextSpaceAudit = false;

    protected static function bootSpaceAuditable(): void
    {
        static::created(function ($model) {
            if ($model->skipNextSpaceAudit) {
                $model->skipNextSpaceAudit = false;

                return;
            }
            $model->recordSpaceAudit('created');
        });

        static::updated(function ($model) {
            if ($model->skipNextSpaceAudit) {
                $model->skipNextSpaceAudit = false;

                return;
            }
            $model->recordSpaceAudit('updated');
        });

        static::deleted(function ($model) {
            if ($model->skipNextSpaceAudit) {
                $model->skipNextSpaceAudit = false;

                return;
            }
            $model->recordSpaceAudit('deleted');
        });
    }

    /**
     * Suppress the automatic audit for the next save/delete on this instance.
     * Use before save() when emitting a lifecycle event explicitly instead.
     */
    public function withoutAudit(): static
    {
        $this->skipNextSpaceAudit = true;

        return $this;
    }

    /**
     * Log a lifecycle audit event explicitly (publish, commit, restore, etc.).
     */
    public function auditSpaceEvent(string $operation, ?AuditActor $actor = null, array $meta = []): void
    {
        $actor ??= $this->resolveSpaceAuditActor();

        app(SpaceAuditLogService::class)->log($this, $operation, $actor, $meta);
    }

    protected function recordSpaceAudit(string $operation, array $meta = []): void
    {
        app(SpaceAuditLogService::class)->log($this, $operation, $this->resolveSpaceAuditActor(), $meta);
    }

    protected function resolveSpaceAuditActor(): AuditActor
    {
        $user = Auth::user();

        return $user ? AuditActor::user($user) : AuditActor::background();
    }
}
