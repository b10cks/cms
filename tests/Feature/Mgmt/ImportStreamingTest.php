<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\AssetDataImportController;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\DataSource;
use App\Models\Space\Icon;
use App\Models\Space\Redirect;
use App\Models\User;
use App\Services\Asset\AssetUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(AssetDataImportController::class)]
#[CoversClass(RespondsWithImportRun::class)]
class ImportStreamingTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    #[Test]
    public function an_asset_import_streams_chunk_progress_and_ends_with_the_result(): void
    {
        $assets = Asset::factory()->count(150)->create();

        $response = $this->stream('assets/import', [
            'file' => $this->assetCsv($assets->map(fn (Asset $asset): array => [$asset->id, 'Jane'])->all()),
        ]);

        $this->assertSame(
            [['processed' => 0, 'total' => 150], ['processed' => 100, 'total' => 150], ['processed' => 150, 'total' => 150]],
            $this->progress($response),
        );
        $this->assertSame(150, $this->doneData($response)['summary']['total_success']);
        $this->assertSame('Jane', $assets->first()->refresh()->data['fields']['_default']['photographer']);
    }

    #[Test]
    public function asset_lookups_and_usage_checks_run_per_chunk_instead_of_per_row(): void
    {
        $assets = Asset::factory()->count(250)->create();

        $this->mock(AssetUsageService::class, function (MockInterface $usage): void {
            $usage->shouldReceive('anyPublished')->times(3)->andReturn(false);
        });

        $selects = 0;
        DB::listen(function ($query) use (&$selects): void {
            if (str_contains($query->sql, 'from "assets"')) {
                $selects++;
            }
        });

        $this->stream('assets/import', [
            'file' => $this->assetCsv($assets->map(fn (Asset $asset): array => [$asset->id, 'Jane'])->all()),
        ]);

        $this->assertSame(3, $selects);
    }

    #[Test]
    public function a_batch_with_a_published_asset_bumps_the_delivery_cache_once(): void
    {
        $assets = Asset::factory()->count(3)->create();
        $this->space->forceFill(['content_updated_at' => now()->subDay()])->save();

        $this->mock(AssetUsageService::class, function (MockInterface $usage) use ($assets): void {
            $usage->shouldReceive('anyPublished')->once()
                ->withArgs(fn (array $ids): bool => $ids === $assets->pluck('id')->all())
                ->andReturn(true);
        });

        $this->stream('assets/import', [
            'file' => $this->assetCsv($assets->map(fn (Asset $asset): array => [$asset->id, 'Jane'])->all()),
        ]);

        $this->assertTrue($this->space->refresh()->content_updated_at->isToday());
    }

    #[Test]
    public function filename_rows_find_their_asset_and_unknown_rows_report_an_error(): void
    {
        $asset = Asset::factory()->create(['filename' => 'hero.jpg']);

        $csv = "filename,photographer\nhero.jpg,Jane\nmissing.jpg,Jane\n";
        $response = $this->stream('assets/import', [
            'file' => UploadedFile::fake()->createWithContent('assets.csv', $csv),
        ]);

        $result = $this->doneData($response);

        $this->assertSame($asset->id, $result['successes'][0]['id']);
        $this->assertSame('Asset not found', $result['errors'][0]['message']);
    }

    #[Test]
    public function a_filename_several_assets_share_is_reported_instead_of_changing_one_of_them(): void
    {
        $first = Asset::factory()->create(['filename' => 'hero.jpg']);
        $second = Asset::factory()->create(['filename' => 'hero.jpg']);
        $before = [$first->data, $second->data];

        $response = $this->stream('assets/import', [
            'file' => UploadedFile::fake()->createWithContent('assets.csv', "filename,photographer\nhero.jpg,Jane\n"),
        ]);

        $this->assertStringContainsString('Several assets', $this->doneData($response)['errors'][0]['message']);
        $this->assertSame($before, [$first->refresh()->data, $second->refresh()->data]);
    }

    #[Test]
    public function a_malformed_asset_csv_row_is_a_row_error_and_blank_lines_are_skipped(): void
    {
        $assets = Asset::factory()->count(2)->create();
        $csv = "id,photographer\n{$assets[0]->id},Jane,extra\n\n{$assets[1]->id},Jane\n";

        $result = $this->doneData($this->stream('assets/import', [
            'file' => $this->assetCsvContent($csv),
        ]));

        $this->assertSame(1, $result['summary']['total_success']);
        $this->assertSame([['row' => 1, 'message' => 'Row has 3 columns, expected 2']], $result['errors']);
        $this->assertSame([], $result['ignored_fields']);
    }

    #[Test]
    public function a_malformed_redirect_csv_row_is_a_row_error_and_blocks_deletions(): void
    {
        $stale = Redirect::query()->create(['source' => '/stale', 'target' => '/x', 'status_code' => 301]);

        $result = $this->doneData($this->stream('redirects/import', [
            'file' => UploadedFile::fake()->createWithContent('redirects.csv', "source,target,status_code\n/new,/y\n/ok,/z,301\n"),
            'import_mode' => 'replacement',
        ]));

        $this->assertSame(1, $result['summary']['total_success']);
        $this->assertSame('Row has 2 columns, expected 3', $result['errors'][0]['message']);
        $this->assertNotNull($stale->fresh());
    }

    #[Test]
    public function a_failed_validation_is_an_error_event_on_a_stream_and_a_422_otherwise(): void
    {
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('assets.csv', "photographer\nJane\n");

        $events = $this->events($this->stream('assets/import', ['file' => $file()]));

        $this->assertSame('error', $events[0]['type']);
        $this->assertStringContainsString('"id" or "filename"', $events[0]['message']);

        $this->post($this->url('assets/import'), ['file' => $file()], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    #[Test]
    public function an_excel_file_is_validated_from_its_header_row(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['source', 'target'], ['/a', '/b']]);
        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->stream('redirects/import', ['file' => new UploadedFile($path, 'redirects.xlsx', test: true)]);

        $this->assertSame(1, $this->doneData($response)['summary']['total_success']);
        $this->assertSame(1, Redirect::query()->count());
    }

    #[Test]
    public function a_redirect_replacement_streams_progress_through_its_deletions(): void
    {
        Redirect::query()->create(['source' => '/stale', 'target' => '/x', 'status_code' => 301]);

        $response = $this->stream('redirects/import', [
            'file' => UploadedFile::fake()->createWithContent('redirects.csv', "source,target,status_code\n/new,/y,301\n"),
            'import_mode' => 'replacement',
        ]);

        $this->assertSame(
            [['processed' => 0, 'total' => 1], ['processed' => 1, 'total' => 1], ['processed' => 1, 'total' => 1]],
            $this->progress($response),
        );
        $this->assertSame(1, $this->doneData($response)['summary']['total_deleted']);
    }

    #[Test]
    public function a_data_entry_import_streams_progress(): void
    {
        $source = DataSource::factory()->create();

        $response = $this->stream("data-sources/{$source->id}/entries/import", [
            'file' => UploadedFile::fake()->createWithContent('entries.csv', "key,value\na,1\nb,2\n"),
        ]);

        $this->assertSame(['processed' => 2, 'total' => 2], $this->progress($response)[1]);
        $this->assertSame(2, $this->doneData($response)['summary']['total_success']);
    }

    #[Test]
    public function an_icon_import_streams_progress(): void
    {
        $response = $this->stream('icons/import', [
            'file' => UploadedFile::fake()->createWithContent('demo.json', json_encode([
                'icons' => [
                    'one' => ['body' => '<path d="M1 1h2"/>'],
                    'two' => ['body' => '<path d="M2 2h2"/>'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([['processed' => 0, 'total' => 2], ['processed' => 2, 'total' => 2]], $this->progress($response));
        $this->assertSame(2, Icon::query()->count());
    }

    #[Test]
    public function a_failed_icon_replacement_keeps_the_old_icons(): void
    {
        $icon = Icon::query()->create(['key' => 'keep', 'name' => 'Keep', 'body' => '<path/>', 'width' => 24, 'height' => 24]);

        Icon::creating(function (): void {
            throw new \RuntimeException('disk full');
        });

        $events = $this->events($this->stream('icons/import', [
            'file' => UploadedFile::fake()->createWithContent('demo.json', json_encode([
                'icons' => ['new' => ['body' => '<path d="M1 1h2"/>']],
            ], JSON_THROW_ON_ERROR)),
            'import_mode' => 'replacement',
        ]));

        $this->assertSame('error', end($events)['type']);
        $this->assertSame('Failed to import icons: disk full', end($events)['message']);
        $this->assertNotNull($icon->fresh()?->id);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /** @param  array<string, mixed>  $payload */
    private function stream(string $path, array $payload): TestResponse
    {
        $response = $this->post($this->url($path), $payload, ['Accept' => 'text/event-stream'])->assertOk();

        // The import only runs once the stream is read.
        $response->streamedContent();

        return $response;
    }

    /** @param  list<array{string, string}>  $rows */
    private function assetCsv(array $rows): UploadedFile
    {
        return $this->assetCsvContent("id,photographer\n".implode("\n", array_map(static fn (array $row): string => implode(',', $row), $rows))."\n");
    }

    private function assetCsvContent(string $csv): UploadedFile
    {
        $this->space->settings = [...$this->space->settings->toArray(), 'asset_fields' => [['key' => 'photographer']]];
        $this->space->save();

        return UploadedFile::fake()->createWithContent('assets.csv', $csv);
    }

    /** @return list<array<string, mixed>> */
    private function events(TestResponse $response): array
    {
        return array_map(
            static fn (string $line): array => json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(
                explode("\n", $response->streamedContent()),
                static fn (string $line): bool => str_starts_with($line, 'data: '),
            )),
        );
    }

    /** @return list<array{processed: int, total: int}> */
    private function progress(TestResponse $response): array
    {
        return array_map(
            static fn (array $event): array => json_decode($event['message'], true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter($this->events($response), static fn (array $event): bool => $event['type'] === 'status')),
        );
    }

    /** @return array<string, mixed> */
    private function doneData(TestResponse $response): array
    {
        $events = $this->events($response);
        $last = end($events);

        $this->assertSame('done', $last['type']);

        return $last['data'];
    }
}
