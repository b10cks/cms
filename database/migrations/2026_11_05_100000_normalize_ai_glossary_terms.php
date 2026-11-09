<?php

use App\Services\Ai\Knowledge\Concepts\GlossaryLocales;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\Ai\Knowledge\LegacyGlossaryRows;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One glossary row is one concept, with its note and Keep exact, and one term per
 * language. The old rows kept a translations map and a copy of the note and flags in
 * every language, and a ban as a copy of its first Avoid wording.
 *
 * Nothing is thrown away: the old table stays as `ai_glossary_terms_legacy`, unread, so
 * a conversion can be compared and undone. A later release can drop it. A term keeps the
 * ID of the old row that held its language, and a language that only a translation named
 * gets a new one. `2026_11_10_110000` drops the old table and `case_sensitive` again:
 * case follows Keep exact.
 *
 * MySQL commits every schema change on its own, so each one is checked by itself, and
 * running this again after a failure picks up where it stopped. Language codes the new
 * table cannot hold stop this before anything changes, with the rows to fix.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    private const LISTED_PROBLEMS = 50;

    public function up(): void
    {
        $this->assertLanguagesConvert();

        if (! Schema::hasColumn('ai_glossaries', 'write_version')) {
            Schema::table('ai_glossaries', function (Blueprint $table) {
                $table->unsignedBigInteger('write_version')->default(0);
            });
        }

        if (! Schema::hasColumn('ai_glossary_concepts', 'note')) {
            Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->text('note')->nullable());
        }

        if (! Schema::hasColumn('ai_glossary_concepts', 'keep_exact')) {
            Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->boolean('keep_exact')->default(false));
        }

        if (! Schema::hasColumn('ai_glossary_concepts', 'case_sensitive')) {
            Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->boolean('case_sensitive')->nullable());
        }

        if (! Schema::hasTable('ai_glossary_terms_legacy')) {
            // A foreign key name is unique in a whole MySQL database, so the old ones go first.
            $this->dropForeignKeys('ai_glossary_terms');
            Schema::rename('ai_glossary_terms', 'ai_glossary_terms_legacy');
        }

        if (! $this->hasForeignKey('ai_glossary_terms_legacy', 'glossary_id')) {
            Schema::table('ai_glossary_terms_legacy', function (Blueprint $table) {
                $table->foreign('glossary_id', 'ai_glossary_terms_legacy_glossary_fk')->references('id')->on('ai_glossaries')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('ai_glossary_terms')) {
            Schema::create('ai_glossary_terms', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('concept_id');
                $table->string('locale', 20);
                // Older files and translations took wording of any length. The limit is on edits.
                $table->longText('preferred')->nullable();
                $table->longText('forbidden')->nullable();
                $table->longText('aliases')->nullable();
                $table->timestamps();
            });
        }

        if (! $this->hasUniqueIndex('ai_glossary_terms', ['concept_id', 'locale'])) {
            Schema::table('ai_glossary_terms', fn (Blueprint $table) => $table->unique(['concept_id', 'locale']));
        }

        if (! $this->hasForeignKey('ai_glossary_terms', 'concept_id')) {
            Schema::table('ai_glossary_terms', fn (Blueprint $table) => $table->foreign('concept_id')->references('id')->on('ai_glossary_concepts')->cascadeOnDelete());
        }

        DB::table('ai_glossaries')->orderBy('id')->pluck('id')->each($this->convert(...));
    }

    /** Stops on what the new table cannot hold, naming each row, before any table changes. */
    private function assertLanguagesConvert(): void
    {
        $table = match (true) {
            Schema::hasTable('ai_glossary_terms_legacy') => 'ai_glossary_terms_legacy',
            Schema::hasColumn('ai_glossary_terms', 'source_locale') => 'ai_glossary_terms',
            default => null,
        };

        if ($table === null) {
            return;
        }

        $problems = [];
        $count = 0;

        DB::table($table)->select(['id', 'glossary_id', 'source_locale', 'translations'])->lazyById(self::CHUNK, 'id')->each(function (object $row) use (&$problems, &$count): void {
            $codes = ['source_locale' => [(string) $row->source_locale]];

            foreach (json_decode((string) $row->translations, true) ?: [] as $code => $wording) {
                if (\is_string($wording) && trim($wording) !== '') {
                    $codes['translations'][] = (string) $code;
                }
            }

            foreach ($codes as $field => $values) {
                foreach ($values as $code) {
                    if (! GlossaryLocales::isValidCode(GlossaryMatcher::normalizeLocale($code))) {
                        $count++;

                        if (\count($problems) < self::LISTED_PROBLEMS) {
                            $problems[] = "glossary {$row->glossary_id}, term {$row->id}, {$field} \"{$code}\"";
                        }
                    }
                }
            }
        });

        if ($problems !== []) {
            throw new RuntimeException(
                "Glossary terms were not converted and nothing changed: {$count} language code(s) are not valid, ".
                'which a person has to decide about. Change or remove them on the term, then run this again. '.
                (\count($problems) < $count ? 'The first '.\count($problems).': ' : '').implode('; ', $problems).'.',
            );
        }
    }

    private function convert(string $glossaryId): void
    {
        $rows = DB::table('ai_glossary_terms_legacy')->where('glossary_id', $glossaryId)->orderBy('id')->get()
            ->map(fn (object $row) => [
                ...(array) $row,
                'translations' => json_decode((string) $row->translations, true),
                'forbidden' => json_decode((string) $row->forbidden, true),
                'source_aliases' => json_decode((string) $row->source_aliases, true),
            ])->all();

        DB::transaction(function () use ($glossaryId, $rows): void {
            $concepts = DB::table('ai_glossary_concepts')->where('glossary_id', $glossaryId);

            if (DB::table('ai_glossary_terms')->whereIn('concept_id', (clone $concepts)->select('id'))->exists()) {
                return;
            }

            $held = (clone $concepts)->pluck('id')->flip();
            $now = now()->toDateTimeString();
            $newConcepts = [];
            $terms = [];

            foreach (LegacyGlossaryRows::concepts($rows) as $concept) {
                $values = ['note' => $concept['note'], 'keep_exact' => $concept['keep_exact']];

                if (isset($held[$concept['id']])) {
                    DB::table('ai_glossary_concepts')->where('id', $concept['id'])->update($values);
                } else {
                    $newConcepts[] = ['id' => $concept['id'], 'glossary_id' => $glossaryId, ...$values, 'created_at' => $now, 'updated_at' => $now];
                }

                foreach ($concept['languages'] as $locale => $language) {
                    $terms[] = [
                        'id' => $concept['term_ids'][$locale] ?? (string) Str::ulid(),
                        'concept_id' => $concept['id'],
                        'locale' => $locale,
                        'preferred' => $language['use'],
                        'forbidden' => json_encode($language['avoid'], JSON_UNESCAPED_UNICODE),
                        'aliases' => json_encode($language['also'], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($newConcepts, self::CHUNK) as $chunk) {
                DB::table('ai_glossary_concepts')->insert($chunk);
            }

            foreach (array_chunk($terms, self::CHUNK) as $chunk) {
                DB::table('ai_glossary_terms')->insert($chunk);
            }

            // A key whose wording was all removed names nothing any more.
            DB::table('ai_glossary_concepts')->where('glossary_id', $glossaryId)
                ->whereNotIn('id', DB::table('ai_glossary_terms')->select('concept_id'))
                ->delete();
        });
    }

    /**
     * Back to the old table as it was converted. Everything written since is lost: rows,
     * notes, Keep exact and term changes alike. Only for undoing a deploy straight away.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_glossary_terms');

        $this->dropForeignKeys('ai_glossary_terms_legacy');
        Schema::rename('ai_glossary_terms_legacy', 'ai_glossary_terms');

        // Concepts this made for rows without one go, and rows whose concept went let go of it.
        $linked = DB::table('ai_glossary_terms')->whereNotNull('concept_id')->select('concept_id');
        $madeHere = DB::table('ai_glossary_terms')->whereNull('concept_id')->select('id');
        DB::table('ai_glossary_concepts')->whereNotIn('id', $linked)->whereIn('id', $madeHere)->delete();
        DB::table('ai_glossary_terms')->whereNotNull('concept_id')
            ->whereNotIn('concept_id', DB::table('ai_glossary_concepts')->select('id'))
            ->update(['concept_id' => null]);

        Schema::table('ai_glossary_terms', function (Blueprint $table) {
            $table->foreign('glossary_id')->references('id')->on('ai_glossaries')->cascadeOnDelete();
            $table->foreign('concept_id')->references('id')->on('ai_glossary_concepts')->nullOnDelete();
        });

        foreach (['note', 'keep_exact', 'case_sensitive'] as $column) {
            Schema::table('ai_glossary_concepts', fn (Blueprint $table) => $table->dropColumn($column));
        }

        Schema::table('ai_glossaries', fn (Blueprint $table) => $table->dropColumn('write_version'));
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $key) => $key['columns'] === [$column]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasUniqueIndex(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['unique'] && $index['columns'] === $columns);
    }

    /** By name where the database has names. SQLite keeps none and takes the columns. */
    private function dropForeignKeys(string $table): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreignKey['name'] ?: $foreignKey['columns']));
        }
    }
};
