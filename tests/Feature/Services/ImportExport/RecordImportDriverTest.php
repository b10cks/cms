<?php

namespace Tests\Feature\Services\ImportExport;

use App\DTOs\ImportExport\ImportResult;
use App\Enums\RedirectImportMode;
use App\Models\Management\Space;
use App\Models\Space\Redirect;
use App\Services\DataEntryData\Drivers\CsvDataEntryDataDriver;
use App\Services\ImportExport\RecordImportDriver;
use App\Services\RedirectData\Drivers\CsvRedirectDataDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Exercises the generic record import skeleton through one concrete subclass
 * (redirects), covering the id -> external_id -> natural key match cascade,
 * change detection, replacement mode deletion and chunking.
 */
#[CoversClass(RecordImportDriver::class)]
class RecordImportDriverTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function it_matches_an_existing_record_by_id_first(): void
    {
        $redirect = Redirect::query()->create([
            'external_id' => 'ext-1',
            'source' => '/a',
            'target' => '/a-target',
            'status_code' => 301,
        ]);
        $other = Redirect::query()->create([
            'external_id' => 'ext-2',
            'source' => '/b',
            'target' => '/b-target',
            'status_code' => 301,
        ]);

        // id wins over both external_id and source, which point elsewhere.
        $result = $this->import([[
            'id' => $redirect->id,
            'external_id' => 'ext-2',
            'source' => '/b',
            'target' => '/updated',
        ]]);

        $this->assertSame([], $result->errors);
        $this->assertSame($redirect->id, $result->successes[0]['id']);
        $this->assertSame('/updated', $redirect->fresh()->target);
        $this->assertSame('/b-target', $other->fresh()->target);
        $this->assertSame(2, Redirect::query()->count());
    }

    #[Test]
    public function it_falls_back_to_external_id_then_to_the_natural_key(): void
    {
        $byExternalId = Redirect::query()->create([
            'external_id' => 'ext-1',
            'source' => '/a',
            'target' => '/a-target',
            'status_code' => 301,
        ]);
        $bySource = Redirect::query()->create([
            'source' => '/b',
            'target' => '/b-target',
            'status_code' => 301,
        ]);

        $result = $this->import([
            ['external_id' => 'ext-1', 'source' => '/a-renamed', 'target' => '/a-updated'],
            ['source' => '/b', 'target' => '/b-updated'],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertSame(2, Redirect::query()->count());

        $byExternalId->refresh();
        $this->assertSame('/a-renamed', $byExternalId->source);
        $this->assertSame('/a-updated', $byExternalId->target);
        $this->assertSame('/b-updated', $bySource->fresh()->target);
    }

    #[Test]
    public function it_creates_a_record_when_nothing_matches(): void
    {
        $result = $this->import([
            ['source' => '/fresh', 'target' => '/fresh-target', 'status_code' => 302],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertCount(1, $result->successes);
        $this->assertDatabaseHas('redirects', [
            'source' => '/fresh',
            'target' => '/fresh-target',
            'status_code' => 302,
        ]);
    }

    #[Test]
    public function a_row_saved_earlier_in_the_same_chunk_is_matched_by_its_natural_key(): void
    {
        $result = $this->import([
            ['source' => '/dupe', 'target' => '/first'],
            ['source' => '/dupe', 'target' => '/second'],
        ]);

        $this->assertSame([], $result->errors);
        $this->assertSame(1, Redirect::query()->count());
        $this->assertSame('/second', Redirect::query()->first()->target);
    }

    #[Test]
    public function it_reports_a_missing_natural_key_value(): void
    {
        $result = $this->import([['target' => '/nowhere']]);

        $this->assertSame([], $result->successes);
        $this->assertSame(
            [['row' => 1, 'message' => 'Missing required "source" value']],
            $result->errors
        );
    }

    #[Test]
    public function it_detects_changes_against_the_tracked_values(): void
    {
        $redirect = Redirect::query()->create([
            'source' => '/a',
            'target' => '/old',
            'status_code' => 301,
        ]);

        $result = $this->import([
            ['id' => $redirect->id, 'source' => '/a', 'target' => '/new', 'status_code' => 308],
        ]);

        $this->assertCount(1, $result->changes);
        $this->assertSame($redirect->id, $result->changes[0]['id']);
        $this->assertSame('/a', $result->changes[0]['source']);
        $this->assertSame(
            [
                ['field' => 'target', 'old' => '/old', 'new' => '/new'],
                ['field' => 'status_code', 'old' => 301, 'new' => 308],
            ],
            $result->changes[0]['changes']
        );
    }

    #[Test]
    public function an_unchanged_row_succeeds_without_reporting_changes(): void
    {
        $redirect = Redirect::query()->create([
            'source' => '/a',
            'target' => '/a-target',
            'status_code' => 301,
        ]);

        $result = $this->import([
            ['id' => $redirect->id, 'source' => '/a', 'target' => '/a-target', 'status_code' => 301],
        ]);

        $this->assertCount(1, $result->successes);
        $this->assertSame([], $result->changes);
    }

    #[Test]
    public function replacement_mode_deletes_untouched_records(): void
    {
        $kept = Redirect::query()->create(['source' => '/keep', 'target' => '/keep-target', 'status_code' => 301]);
        $dropped = Redirect::query()->create(['source' => '/drop', 'target' => '/drop-target', 'status_code' => 301]);

        $result = $this->import(
            [['source' => '/keep', 'target' => '/keep-target']],
            RedirectImportMode::Replacement
        );

        $this->assertSame([['id' => $dropped->id, 'source' => '/drop']], $result->deleted);
        $this->assertSame([$kept->id], Redirect::query()->pluck('id')->all());
    }

    #[Test]
    public function replacement_mode_keeps_everything_when_a_row_failed(): void
    {
        Redirect::query()->create(['source' => '/keep', 'target' => '/keep-target', 'status_code' => 301]);
        Redirect::query()->create(['source' => '/drop', 'target' => '/drop-target', 'status_code' => 301]);

        $result = $this->import(
            [['target' => '/no-source']],
            RedirectImportMode::Replacement
        );

        $this->assertCount(1, $result->errors);
        $this->assertSame([], $result->deleted);
        $this->assertSame(2, Redirect::query()->count());
    }

    #[Test]
    public function addition_mode_never_deletes(): void
    {
        Redirect::query()->create(['source' => '/drop', 'target' => '/drop-target', 'status_code' => 301]);

        $result = $this->import([['source' => '/keep', 'target' => '/keep-target']]);

        $this->assertSame([], $result->deleted);
        $this->assertSame(2, Redirect::query()->count());
    }

    #[Test]
    public function rows_are_processed_in_chunks_of_the_configured_size(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['source' => "/row-{$i}", 'target' => "/target-{$i}"];
        }

        $driver = new SmallChunkRecordImportDriver($rows);

        $result = $driver->run($this->space, RedirectImportMode::Addition);

        $this->assertCount(5, $result->successes);
        // 5 rows / chunk size 2 => 3 transactions, each with a single prefetch.
        $this->assertSame(3, $driver->prefetchCalls);
        $this->assertSame(3, $driver->transactionCount());
        $this->assertSame(5, Redirect::query()->count());
    }

    #[Test]
    public function deletions_are_chunked_too(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Redirect::query()->create(['source' => "/old-{$i}", 'target' => "/t-{$i}", 'status_code' => 301]);
        }

        $driver = new SmallChunkRecordImportDriver([['source' => '/kept', 'target' => '/kept-target']]);

        $result = $driver->run($this->space, RedirectImportMode::Replacement);

        $this->assertCount(5, $result->deleted);
        // 1 import chunk + 3 deletion chunks (5 stale ids / 2).
        $this->assertSame(4, $driver->transactionCount());
        $this->assertSame(['/kept'], Redirect::query()->pluck('source')->all());
    }

    #[Test]
    public function it_reports_unknown_headers_as_ignored_fields(): void
    {
        $result = $this->import([
            ['source' => '/a', 'target' => '/a-target', 'nonsense' => 'x', '' => 'y'],
        ]);

        $this->assertSame(['nonsense'], $result->ignoredFields);
    }

    #[Test]
    public function an_empty_file_is_reported_as_such(): void
    {
        $result = $this->import([]);

        $this->assertSame([['message' => 'File is empty']], $result->errors);
    }

    #[Test]
    public function the_default_duplicate_key_message_reproduces_the_existing_wording(): void
    {
        $this->assertSame(
            'A redirect with this source already exists',
            $this->duplicateKeyMessage(app(CsvRedirectDataDriver::class))
        );

        $this->assertSame(
            'A data entry with this key already exists',
            $this->duplicateKeyMessage(app(CsvDataEntryDataDriver::class))
        );
    }

    private function duplicateKeyMessage(RecordImportDriver $driver): string
    {
        $method = new ReflectionMethod($driver, 'duplicateKeyMessage');

        return $method->invoke($driver);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function import(array $rows, RedirectImportMode $mode = RedirectImportMode::Addition): ImportResult
    {
        return new TestRecordImportDriver($rows)->run($this->space, $mode);
    }
}

/**
 * Minimal concrete driver over the Redirect model: the parse step is replaced
 * by canned rows so the test exercises the shared skeleton only.
 */
class TestRecordImportDriver extends RecordImportDriver
{
    public int $prefetchCalls = 0;

    private array $transactions = [];

    public function __construct(private readonly array $rows)
    {
    }

    public function run(Space $space, RedirectImportMode $mode): ImportResult
    {
        $connection = new Redirect()->getConnection();
        $connection->beforeStartingTransaction(function (): void {
            $this->transactions[] = true;
        });

        return $this->runImport($space, UploadedFile::fake()->create('rows.json'), $mode);
    }

    public function transactionCount(): int
    {
        return count($this->transactions);
    }

    public function parseFile(UploadedFile $file): array
    {
        return $this->rows;
    }

    public function getFormat(): string
    {
        return 'test';
    }

    protected function prefetchRecords(array $chunk): array
    {
        $this->prefetchCalls++;

        return parent::prefetchRecords($chunk);
    }

    protected function importableColumns(): array
    {
        return ['id', 'external_id', 'source', 'target', 'status_code'];
    }

    protected function naturalKeyColumn(): string
    {
        return 'source';
    }

    protected function newModel(): Redirect
    {
        return new Redirect();
    }

    protected function newQuery(): Builder
    {
        return Redirect::query();
    }

    protected function importLogLabel(): string
    {
        return 'Redirect';
    }

    protected function castColumnValue(string $column, mixed $value): mixed
    {
        return $column === 'status_code' ? (int) $value : $value;
    }

    protected function extractTrackedValues(Model $record): array
    {
        return [
            'external_id' => $record->external_id,
            'source' => $record->source,
            'target' => $record->target,
            'status_code' => $record->status_code,
        ];
    }
}

/** Same driver with a tiny chunk size so chunking is observable. */
class SmallChunkRecordImportDriver extends TestRecordImportDriver
{
    protected const IMPORT_CHUNK_SIZE = 2;
}
