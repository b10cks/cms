<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('provider_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 150);
            $table->string('icon', 100)->nullable();
            $table->string('url')->nullable();
            $table->char('color', 7)->nullable()->charset('ascii');
            $table->text('content')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_notes');
    }
};
