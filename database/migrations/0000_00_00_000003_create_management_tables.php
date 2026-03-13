<?php

use App\Enums\RoleScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        // Audit Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action')->charset('ascii');
            $table->ulidMorphs('entity');

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->char('hash', 60)->charset('ascii');

            $table->timestamp('created_at');

            $table->index('action');
            $table->index('created_at');
        });

        // Teams
        Schema::create('teams', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->string('avatar', 150)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');
            $table->string('type', 50)->nullable()->charset('ascii');

            $table->text('description')->nullable();

            $table->json('settings')->nullable();

            $table->foreignUlid('parent_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Roles
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('scope', 20)->charset('ascii');
            $table->string('key', 100)->charset('ascii');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->integer('level');
            $table->boolean('is_system')->default(true);
            $table->json('abilities');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scope', 'team_id', 'key']);
            $table->index(['scope', 'team_id', 'key']);
        });

        $this->seedSystemRoles();

        // Team User Pivot
        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignUlid('team_id')->constrained()->onDelete('cascade');
            $table->foreignUlid('user_id')->constrained()->onDelete('cascade');
            $table->foreignUlid('role_id')->constrained('roles')->nullOnDelete();

            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index(['team_id', 'user_id']);
            $table->index('role_id');
        });

        // Spaces
        Schema::create('spaces', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('state', 50)->default('draft')->charset('ascii');

            $table->string('name', 100);
            $table->string('slug', 50)->charset('ascii');
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');
            $table->string('badge', 50)->nullable();
            $table->text('description')->nullable();

            $table->json('settings')->nullable();

            $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('content_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['slug', 'deleted_at']);
        });

        // Space Connections
        Schema::create('space_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained();

            $table->string('state', 50)->default('draft')->charset('ascii');

            $table->string('name', 100);
            $table->text('description')->nullable();

            $table->string('driver')->charset('ascii');
            $table->text('config')->nullable();
            $table->json('settings')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        // Storages
        Schema::create('storages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained()->cascadeOnDelete();

            $table->string('state', 50)->default('draft')->charset('ascii');
            $table->string('name');
            $table->string('slug', 50)->charset('ascii');
            $table->string('icon', 50)->nullable();
            $table->string('color')->nullable()->charset('ascii');

            $table->text('description')->nullable();
            $table->string('driver')->charset('ascii');

            $table->text('config')->nullable();
            $table->json('settings')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_managed')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['space_id', 'slug']);
        });

        // Space User Pivot
        Schema::create('space_user', function (Blueprint $table) {
            $table->foreignUlid('space_id')->constrained()->onDelete('cascade');
            $table->foreignUlid('user_id')->constrained()->onDelete('cascade');
            $table->foreignUlid('role_id')->constrained('roles')->nullOnDelete();

            $table->timestamps();

            $table->unique(['space_id', 'user_id']);
            $table->index(['space_id', 'user_id']);
            $table->index('role_id');
        });

        // Permissions
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulidMorphs('resource');
            $table->foreignUlid('user_id')->constrained()->onDelete('cascade');

            $table->string('action', 50)->charset('ascii');
            $table->json('conditions')->nullable();

            $table->timestamps();
        });

        // User Invites
        Schema::create('invites', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('space_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignUlid('team_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignUlid('invited_by')->constrained('users')->onDelete('cascade');
            $table->foreignUlid('invitee_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignUlid('role_id')->constrained('roles')->nullOnDelete();

            $table->text('message')->nullable();
            $table->string('email');
            $table->string('token')->unique();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->unique(['space_id', 'email']);
            $table->unique(['team_id', 'email']);
            $table->index('role_id');
        });

        // LemonSqueezy Subscriptions
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained()->onDelete('cascade');

            $table->string('name');
            $table->string('lemon_squeezy_id')->unique(); // LemonSqueezy subscription ID
            $table->string('status'); // active, cancelled, expired
            $table->string('variant_id'); // LemonSqueezy variant ID
            $table->string('product_id'); // LemonSqueezy product ID
            $table->integer('quantity')->default(1);

            $table->timestamp('renews_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            $table->json('attributes')->nullable(); // Store any additional subscription attributes

            $table->timestamps();
        });

        // Base Tokens
        Schema::create('tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained();

            $table->string('name');
            $table->string('token', 150)->charset('ascii');
            $table->json('abilities')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->unsignedBigInteger('execution_limit')->nullable();
            $table->unsignedBigInteger('execution_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(['space_id', 'token']);
        });

        Schema::create('token_usage_stats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('token_id')->constrained('tokens')->cascadeOnDelete();

            $table->enum('period_type', array_column(\App\Enums\PeriodType::cases(), 'value'))->charset('ascii');
            $table->date('period_date');

            $table->unsignedInteger('total_executions')->default(0);
            $table->unsignedInteger('successful_executions')->default(0);
            $table->unsignedInteger('failed_executions')->default(0);
            $table->decimal('avg_duration_ms', 10, 2)->nullable();

            $table->timestamps();

            $table->unique(['token_id', 'period_type', 'period_date']);
            $table->index(['period_date', 'period_type']);
        });

        // Create token executions table
        Schema::create('token_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('token_id')->constrained('tokens')->cascadeOnDelete();

            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->float('duration')->nullable();

            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('completed_at', 3)->nullable();

            $table->index(['token_id', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        // Space Automations
        Schema::create('automations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained()->onDelete('cascade');

            $table->string('name');
            $table->text('description')->nullable();

            $table->json('trigger')->nullable();
            $table->json('action')->nullable();
            $table->json('secrets')->nullable();

            $table->unsignedBigInteger('execution_limit')->nullable();
            $table->unsignedBigInteger('execution_count')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // Space Automations Executions
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->charset('ascii');  // queued, running, completed, failed
            $table->json('context')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();

            $table->float('duration')->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('completed_at', 3)->nullable();
            $table->timestamp('created_at');

            $table->index(['automation_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Space Automations Usage Stats
        Schema::create('automation_usage_stats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->constrained()->cascadeOnDelete();

            $table->enum('period_type', array_column(\App\Enums\PeriodType::cases(), 'value'))->charset('ascii');
            $table->date('period_date');

            $table->unsignedInteger('total_executions')->default(0);
            $table->unsignedInteger('successful_executions')->default(0);
            $table->unsignedInteger('failed_executions')->default(0);
            $table->decimal('avg_duration_ms', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_usage_stats');
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automations');
        Schema::dropIfExists('storage_files');
        Schema::dropIfExists('token_usage_stats');
        Schema::dropIfExists('token_executions');
        Schema::dropIfExists('tokens');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('invites');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('space_user');
        Schema::dropIfExists('space_connections');
        Schema::dropIfExists('storages');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('audit_logs');
    }

    private function seedSystemRoles(): void
    {
        $now = now();

        foreach (config('authorization.roles') as $scope => $roles) {
            foreach ($roles as $key => $definition) {
                DB::table('roles')->updateOrInsert(
                    [
                        'scope' => $scope,
                        'team_id' => null,
                        'key' => $key,
                    ],
                    [
                        'id' => DB::table('roles')
                            ->where('scope', $scope)
                            ->whereNull('team_id')
                            ->where('key', $key)
                            ->value('id') ?? (string) Str::ulid(),
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'level' => $definition['level'],
                        'is_system' => true,
                        'abilities' => json_encode($definition['abilities'], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
};
