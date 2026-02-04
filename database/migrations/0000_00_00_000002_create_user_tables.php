<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('firstname');
            $table->string('lastname');
            $table->string('avatar')->nullable();
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_backup_codes')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();

            $table->string('source')->charset('ascii')->default('website');
            $table->string('language_iso', 5)->charset('ascii')->default('en');
            $table->json('settings')->nullable();

            $table->unsignedBigInteger('login_count')->default(0);
            $table->timestamp('last_login_at')->nullable();

            $table->boolean('is_root')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['id', 'deleted_at']);
            $table->index(['email', 'deleted_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_social_links', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('external_id');
            $table->string('service')->charset('ascii');
            $table->string('token')->nullable();

            $table->foreignUlid('user_id');

            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('user_social_links');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
