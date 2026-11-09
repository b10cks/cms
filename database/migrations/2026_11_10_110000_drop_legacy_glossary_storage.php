<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops what the glossary normalization kept for comparing and undoing: the old term
 * table, and `case_sensitive` on concepts, since case now follows Keep exact. Both were
 * empty on production when this shipped. There is no way back to either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_glossary_terms_legacy');

        if (Schema::hasColumn('ai_glossary_concepts', 'case_sensitive')) {
            Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->dropColumn('case_sensitive'));
        }
    }

    public function down(): void
    {
        Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->boolean('case_sensitive')->nullable());
    }
};
