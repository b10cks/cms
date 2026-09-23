<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_classification_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->unsignedInteger('queued')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->boolean('enqueuing')->default(true);
            $table->timestamps();
            $table->index(['space_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_classification_runs');
    }
};
