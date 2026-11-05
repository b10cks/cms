<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Stable concepts and the term fields they need.
 *
 * Additive only. Existing terms keep working with every new column null and
 * gain a concept the first time something writes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_glossary_concepts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('glossary_id')->constrained('ai_glossaries')->cascadeOnDelete();
            $this->exact($table->string('key', 120)->nullable());
            $table->timestamps();
            $table->unique(['glossary_id', 'key']);
        });

        Schema::table('ai_glossary_terms', function (Blueprint $table) {
            $table->foreignUlid('concept_id')->nullable()->constrained('ai_glossary_concepts')->nullOnDelete();
            $table->longText('source_aliases')->nullable();
            $table->unique(['concept_id', 'source_locale']);
        });
    }

    /** Identity keys compare exactly; MySQL's default collation would treat `färbe` and `farbe` as one. */
    private function exact(ColumnDefinition $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }
    }

    public function down(): void
    {
        Schema::table('ai_glossary_terms', function (Blueprint $table) {
            // The unique index can stand in for the foreign key's own, so the key goes first.
            $table->dropForeign(['concept_id']);
            $table->dropUnique(['concept_id', 'source_locale']);
            $table->dropColumn(['concept_id', 'source_aliases']);
        });

        Schema::dropIfExists('ai_glossary_concepts');
    }
};
