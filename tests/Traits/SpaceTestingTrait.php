<?php

namespace Tests\Traits;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\System\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait SpaceTestingTrait
{
    protected function runSpaceMigrations(): void
    {
        if (Schema::hasTable('blocks')) {
            return;
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }

        $this->artisan('migrate', [
            '--path' => 'database/migrations/spaces',
            '--realpath' => true,
        ]);
    }

    protected function setUpSpaceTesting(Space $space): void
    {
        SpaceConnection::forceCreate([
            'name' => 'internal',
            'space_id' => $space->id,
            'driver' => 'sqlite',
            'config' => [
                'database' => ':memory:',
            ],
        ]);
        $this->runSpaceMigrations();
        $this->stubManagementAuditService();
        app('router')->getCurrentRoute()?->setParameter('space', $space);
        app()->offsetSet('currentSpace', $space);
    }

    /**
     * Management and space both own a table called `audit_logs`, kept apart in
     * production by living in separate databases. Tests share one connection, so
     * runSpaceMigrations() drops the management table to make room for the space
     * one — which leaves the management writer pointing at a schema that has no
     * user_id, and every actingAs request failing on the audit write.
     *
     * Stubbing the writer here keeps that trade-off with the code that causes it.
     * logChanges() delegates to log(), so overriding log() covers both.
     */
    protected function stubManagementAuditService(): void
    {
        app()->instance(AuditService::class, new class extends AuditService
        {
            public function log(
                string $action,
                Model $entity,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?array $metadata = null,
                ?User $user = null,
            ): AuditLog {
                return new AuditLog;
            }
        });
    }
}
