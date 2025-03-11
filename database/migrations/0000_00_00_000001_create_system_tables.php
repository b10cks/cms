<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->char('iso2', 2)->charset('ascii')->primary();

            $table->string('name_local')->nullable();
            $table->json('name_translation')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['iso2', 'deleted_at']);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->string('iso', 5)->charset('ascii')->primary();
            $table->string('locale', 15)->charset('ascii')->nullable();

            $table->string('name');
            $table->string('name_local');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['iso', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
        Schema::dropIfExists('languages');
    }
};
