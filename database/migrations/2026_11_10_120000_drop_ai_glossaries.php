<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The AI glossary is gone, with no replacement check. Before its tables go, each
 * glossary is written with its concepts and terms as JSON to the `transfers` disk
 * under `glossary-archive/{Y-m-d-His}/{glossary_id}.json`, so an operator can hand
 * a customer their rows. A write that fails stops this before anything is dropped.
 *
 * `down()` brings the tables back empty; the archive is not read back.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        if (Schema::hasTable('ai_glossaries')) {
            $this->archive('glossary-archive/'.now()->format('Y-m-d-His'));
        }

        Schema::dropIfExists('ai_glossary_terms');
        Schema::dropIfExists('ai_glossary_concepts');
        Schema::dropIfExists('ai_glossaries');
    }

    private function archive(string $directory): void
    {
        $disk = Storage::disk('transfers');
        $hasConcepts = Schema::hasTable('ai_glossary_concepts');
        $hasTerms = $hasConcepts && Schema::hasTable('ai_glossary_terms') && Schema::hasColumn('ai_glossary_terms', 'concept_id');

        DB::table('ai_glossaries')->lazyById(self::CHUNK, 'id')->each(function (object $glossary) use ($disk, $directory, $hasConcepts, $hasTerms): void {
            $concepts = $hasConcepts ? DB::table('ai_glossary_concepts')->where('glossary_id', $glossary->id)->orderBy('id')->get() : collect();
            $terms = $hasTerms && $concepts->isNotEmpty()
                ? DB::table('ai_glossary_terms')->whereIn('concept_id', $concepts->pluck('id'))->orderBy('id')->get()->groupBy('concept_id')
                : collect();

            $document = [
                'glossary' => (array) $glossary,
                'concepts' => $concepts->map(fn (object $concept) => [
                    ...(array) $concept,
                    'terms' => ($terms[$concept->id] ?? collect())->map(fn (object $term) => [
                        ...(array) $term,
                        'forbidden' => self::decoded($term->forbidden ?? null),
                        'aliases' => self::decoded($term->aliases ?? null),
                    ])->values()->all(),
                ])->values()->all(),
            ];

            $path = "{$directory}/{$glossary->id}.json";
            if (! $disk->put($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException("Could not write {$path} to the transfers disk, so no glossary table was dropped.");
            }
        });
    }

    private static function decoded(mixed $value): mixed
    {
        return \is_string($value) ? (json_decode($value, true) ?? $value) : $value;
    }

    public function down(): void
    {
        Schema::create('ai_glossaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 10);
            $table->foreignUlid('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignUlid('space_id')->nullable()->constrained('spaces')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unsignedBigInteger('write_version')->default(0);
            $table->index(['scope', 'space_id', 'team_id']);
        });

        Schema::create('ai_glossary_concepts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('glossary_id')->constrained('ai_glossaries')->cascadeOnDelete();
            $this->exact($table->string('key', 120)->nullable());
            $table->timestamps();
            $table->text('note')->nullable();
            $table->boolean('keep_exact')->default(false);
            $table->unique(['glossary_id', 'key']);
        });

        Schema::create('ai_glossary_terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('concept_id');
            $table->string('locale', 20);
            $table->longText('preferred')->nullable();
            $table->longText('forbidden')->nullable();
            $table->longText('aliases')->nullable();
            $table->timestamps();
            $table->unique(['concept_id', 'locale']);
            $table->foreign('concept_id')->references('id')->on('ai_glossary_concepts')->cascadeOnDelete();
        });
    }

    /** Identity keys compare exactly, as `2026_11_02_100000` made them. */
    private function exact(ColumnDefinition $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }
    }
};
