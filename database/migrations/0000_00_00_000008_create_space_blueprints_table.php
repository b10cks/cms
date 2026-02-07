<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('space_blueprints', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 100);
            $table->string('icon', 50)->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');
            $table->text('description')->nullable();

            $table->json('settings')->nullable();
            $table->json('data')->nullable();

            $table->foreignUlid('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_blueprints');
    }
};
