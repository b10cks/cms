<?php

namespace Tests\Traits;

use App\Models\Management\Space;
use App\Models\Management\SpaceConnection;
use App\Models\System\AuditLog;
use App\Models\User;
use App\Services\Database\SpaceModelResolver;
use App\Services\System\AuditService;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
     * Give the space its own prefixed in-memory database, the way a shared
     * space connection works, instead of the test connection every other test
     * shares. Space models resolve it only inside the space's route or
     * context, like in production, so a query on the wrong connection or
     * outside the context fails.
     */
    protected function setUpSeparateSpaceDatabase(Space $space): void
    {
        config(['database.connections.space_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'space_',
            'foreign_key_constraints' => true,
        ]]);
        $this->artisan('migrate', ['--database' => 'space_test', '--path' => 'database/migrations/spaces', '--realpath' => true]);

        $this->app->instance(SpaceModelResolver::class, new class($space->id) extends SpaceModelResolver
        {
            public function __construct(private readonly string $spaceId)
            {
                parent::__construct();
            }

            public function getDefaultConnection()
            {
                $space = request()->route('space');
                $space = $space instanceof Space ? $space : SpaceContext::current();
                abort_unless($space?->id === $this->spaceId, 404, 'Space not found');

                return DB::connection('space_test');
            }
        });
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
