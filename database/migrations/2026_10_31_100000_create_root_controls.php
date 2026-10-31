<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root controls for the provider console: time-boxed elevation, recorded
 * impersonations and user suspension.
 *
 * `session_epoch` invalidates browser sessions without enumerating them: each
 * session remembers the epoch it was created under, and bumping the column
 * ends every session that carries an older value (see AuthenticateSession).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_elevations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('impersonations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('impersonator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('reason', 500);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_root');
            $table->ulid('suspended_by')->nullable()->after('suspended_at');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_by');
            $table->unsignedInteger('session_epoch')->default(0)->after('suspension_reason');

            $table->index('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['suspended_at', 'suspended_by', 'suspension_reason', 'session_epoch']);
        });

        Schema::dropIfExists('impersonations');
        Schema::dropIfExists('root_elevations');
    }
};
