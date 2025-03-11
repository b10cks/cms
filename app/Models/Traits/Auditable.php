<?php

namespace App\Models\Traits;

use App\Services\System\AuditService;
use Arr;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    protected static array $auditQueue = [];

    /**
     * Boot the trait
     */
    protected static function bootAuditable(): void
    {
        // Queue audits for after commit to ensure data consistency
        static::created(function (Model $model) {
            static::queueAudit('created', $model);
            static::processAuditQueue();
        });

        static::updated(function (Model $model) {
            static::queueAudit('updated', $model);
            static::processAuditQueue();
        });

        static::deleted(function (Model $model) {
            static::queueAudit('deleted', $model);
        });

        // Process queued audits after successful commit
        static::saved(function () {
            static::processAuditQueue();
        });
    }

    /**
     * Get the fields that should be audited.
     * Override this in your model to customize.
     */
    public function getAuditableFields(): array
    {
        return $this->fillable;
    }

    /**
     * Get the fields that should be excluded from auditing.
     * Override this in your model to customize.
     */
    public function getAuditExcludedFields(): array
    {
        return array_merge(
            $this->auditExcluded ?? [],
            ['created_at', 'updated_at', 'deleted_at']
        );
    }

    /**
     * Get fields that should have their values redacted in logs.
     * Override this in your model to customize.
     */
    public function getAuditRedactedFields(): array
    {
        return $this->auditRedacted ?? [];
    }

    /**
     * Get the redaction value for a field.
     * Override this in your model to customize.
     */
    public function getRedactionValue(string $field): string
    {
        return '[REDACTED]';
    }

    /**
     * Get the events that should trigger audits.
     * Override this in your model to customize.
     */
    public function getAuditableEvents(): array
    {
        return $this->auditableEvents ?? ['created', 'updated', 'deleted'];
    }

    /**
     * Get metadata for audit log.
     * Override this in your model to customize.
     */
    public function getAuditMetadata(): ?array
    {
        return null;
    }

    /**
     * Queue an audit for processing after commit
     */
    protected static function queueAudit(string $event, Model $model): void
    {
        if (!in_array($event, $model->getAuditableEvents())) {
            return;
        }

        $changes = static::prepareChanges($model);
        if (empty($changes) && $event !== 'deleted') {
            return;
        }

        static::$auditQueue[] = [
            'event' => $event,
            'model' => $model,
            'changes' => $changes
        ];
    }

    /**
     * Process the queued audits
     */
    protected static function processAuditQueue(): void
    {
        if (empty(static::$auditQueue)) {
            return;
        }

        $auditService = app(AuditService::class);


        foreach (static::$auditQueue as $audit) {
            $auditService->log(
                action: $audit['event'],
                entity: $audit['model'],
                oldValues: static::getOldValues($audit['changes'], $audit['model']),
                newValues: static::getNewValues($audit['changes'], $audit['model']),
                metadata: $audit['model']->getAuditMetadata()
            );
        }

        static::$auditQueue = [];
    }

    /**
     * Prepare changes for auditing
     */
    protected static function prepareChanges(Model $model): array
    {
        $changes = $model->isDirty() ? $model->getDirty() : [];

        if ($model->wasRecentlyCreated) {
            $changes = $model->getAttributes();
        }

        // Filter fields
        $auditableFields = array_diff(
            $model->getAuditableFields(),
            $model->getAuditExcludedFields()
        );

        return array_intersect_key($changes, array_flip($auditableFields));
    }

    /**
     * Get old values for audit
     */
    protected static function getOldValues(array $changes, Model $model): array
    {
        if ($model->wasRecentlyCreated) {
            return [];
        }

        $values = array_map(function ($key) use ($model) {
            return $model->getOriginal($key);
        }, array_keys($changes));

        return static::redactValues(array_combine(array_keys($changes), $values), $model);
    }

    /**
     * Get new values for audit
     */
    protected static function getNewValues(array $changes, Model $model): array
    {
        return static::redactValues($changes, $model);
    }

    /**
     * Redact sensitive values
     */
    protected static function redactValues(array $values, Model $model): array
    {
        $processed = $values;
        foreach ($model->getAuditRedactedFields() as $field) {
            if (Arr::has($processed, $field)) {
                data_set($processed, $field, $model->getRedactionValue($field));
            }
        }

        return $processed;
    }
}
