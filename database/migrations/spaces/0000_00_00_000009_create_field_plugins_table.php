<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_plugins', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_id', 36)->nullable()->index();

            $table->string('name', 100);
            $table->string('handle', 64)->charset('ascii');
            $table->text('description')->nullable();

            $table->boolean('dev_mode')->default(false);
            $table->string('dev_url', 2048)->nullable();

            $table->longText('code')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->unsignedInteger('code_size')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->json('manifest')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_plugins');
    }
};
