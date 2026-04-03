<?php

namespace App\Services\Audit;

use App\Models\Space\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SpaceAuditLogService
{
    public function log(
        object $model,
        string $operation,
        AuditActor $actor,
        array $meta = [],
    ): void {
        $type = AuditSubjectRegistry::getType($model);

        if (! $type) {
            return;
        }

        $id = $model instanceof Model ? $model->getKey() : ($model->id ?? null);
        $name = AuditSubjectRegistry::getLabel($model);

        $metaPayload = array_merge(
            $actor->systemKey ? ['system_key' => $actor->systemKey] : [],
            $meta,
        );

        $attributes = [
            'referenced_type' => $type,
            'referenced_id' => (string) $id,
            'name' => $name,
            'owner_type' => $actor->type,
            'owner_id' => $actor->id,
            'owner_name' => $actor->name,
            'operation' => $operation,
            'meta' => $metaPayload ?: null,
        ];

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($attributes) {
                AuditLog::create($attributes);
            });
        } else {
            AuditLog::create($attributes);
        }
    }
}
