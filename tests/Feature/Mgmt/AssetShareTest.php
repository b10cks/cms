<?php

namespace Tests\Feature\Mgmt;

use App\Http\Controllers\Mgmt\PublicAssetShareController;
use App\Jobs\Space\BuildAssetPackageJob;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\AssetPackage;
use App\Models\Space\AssetShare;
use App\Models\User;
use App\Services\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(PublicAssetShareController::class)]
class AssetShareTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    protected User $user;

    protected Space $space;

    protected Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // The public share endpoints are rate limited per IP; the limiter
        // counters live in the cache and would otherwise bleed across tests.
        Cache::flush();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');

        $this->storage = Storage::factory()->create([
            'space_id' => $this->space->id,
            'is_default' => true,
            'config' => [
                'root' => storage_path("app/spaces/{$this->space->id}"),
            ],
            'driver' => 'local',
            'state' => 'live',
        ]);

        Sanctum::actingAs($this->user);

        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
    }

    private function createAssetWithFile(string $filename = 'photo', string $contents = 'file-contents'): Asset
    {
        $asset = Asset::create([
            'filename' => $filename,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'storage_id' => $this->storage->id,
            'size' => strlen($contents),
            'metadata' => ['type' => 'image', 'width' => 10, 'height' => 10],
        ]);

        $asset->path = "{$this->space->id}/{$asset->id}/{$filename}.jpg";
        $asset->save();

        app(StorageService::class)
            ->getDefaultStorage($this->space)
            ->put($asset->path, $contents);

        return $asset;
    }

    private function createShare(array $attributes = []): AssetShare
    {
        return AssetShare::create([
            'token' => AssetShare::generateToken(),
            'name' => 'Press kit',
            'source_type' => 'selection',
            'asset_ids' => $attributes['asset_ids'] ?? [],
            ...$attributes,
        ]);
    }

    private function configureCloudfrontSigning(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        config()->set('services.cloudfront.signing', [
            'key_pair_id' => 'APKATESTTESTTEST',
            'private_key' => $pem,
            'download_base_url' => 'https://dl.test',
        ]);
    }

    #[Test]
    public function it_filters_the_index_by_collection(): void
    {
        $forCollection = $this->createShare([
            'name' => 'Brand kit',
            'source_type' => 'collection',
            'collection_id' => 'coll1',
        ]);
        $this->createShare([
            'name' => 'Other collection',
            'source_type' => 'collection',
            'collection_id' => 'coll2',
        ]);
        $this->createShare(['name' => 'A selection']);

        $response = $this->getJson(route('mgmt.asset-shares.index', [
            'space' => $this->space,
            'source_type' => 'collection',
            'collection_id' => 'coll1',
        ]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $forCollection->id);
    }

    #[Test]
    public function it_creates_a_share_with_token_and_dispatches_a_package_build(): void
    {
        Queue::fake();

        $asset = $this->createAssetWithFile();

        $response = $this->postJson(route('mgmt.asset-shares.store', $this->space), [
            'name' => 'Press kit',
            'source_type' => 'selection',
            'asset_ids' => [$asset->id],
            'password' => 'secret-pass',
            'download_limit' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Press kit')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.download_limit', 5);

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(48, strlen($token));

        $share = AssetShare::where('token', $token)->firstOrFail();
        $this->assertNotNull($share->package_id);
        $this->assertNotEquals('secret-pass', $share->password);

        Queue::assertPushed(BuildAssetPackageJob::class);
    }

    #[Test]
    public function build_job_creates_a_zip_package_on_the_transfers_disk(): void
    {
        LaravelStorage::fake('transfers');

        $assetA = $this->createAssetWithFile('photo');
        $assetB = $this->createAssetWithFile('photo'); // duplicate filename → " (2)" suffix

        $package = AssetPackage::create([
            'name' => 'Press Kit',
            'source_type' => 'selection',
            'asset_ids' => [$assetA->id, $assetB->id],
        ]);

        (new BuildAssetPackageJob($package, $this->space))->handle();

        $package->refresh();

        $this->assertSame(AssetPackage::STATE_COMPLETED, $package->state);
        $this->assertSame(2, $package->asset_count);
        $this->assertSame(100, $package->progress);
        $this->assertNotNull($package->checksum);
        $this->assertNotNull($package->expires_at);
        $this->assertSame(
            "packages/{$this->space->id}/{$package->id}/press-kit.zip",
            $package->s3_path
        );

        LaravelStorage::disk('transfers')->assertExists($package->s3_path);

        $zip = new \ZipArchive;
        $zip->open(LaravelStorage::disk('transfers')->path($package->s3_path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('photo.jpg'));
        $this->assertNotFalse($zip->locateName('photo (2).jpg'));
        $zip->close();
    }

    #[Test]
    public function build_job_marks_the_package_failed_when_the_source_is_empty(): void
    {
        LaravelStorage::fake('transfers');

        $package = AssetPackage::create([
            'source_type' => 'selection',
            'asset_ids' => [],
        ]);

        try {
            (new BuildAssetPackageJob($package, $this->space))->handle();
            $this->fail('Expected the build to throw');
        } catch (\RuntimeException) {
            // sync execution surfaces the failure to the caller
        }

        (new BuildAssetPackageJob($package, $this->space))->failed(new \RuntimeException('The selection contains no assets.'));

        $this->assertSame(AssetPackage::STATE_FAILED, $package->fresh()->state);
        $this->assertNotNull($package->fresh()->error);
    }

    #[Test]
    public function unknown_revoked_and_expired_shares_are_indistinguishable_404s(): void
    {
        $revoked = $this->createShare(['revoked_at' => now()]);
        $expired = $this->createShare(['expires_at' => now()->subMinute()]);

        $this->getJson(route('mgmt.shares.show', [$this->space, 'nope']))->assertNotFound();
        $this->getJson(route('mgmt.shares.show', [$this->space, $revoked->token]))->assertNotFound();
        $this->getJson(route('mgmt.shares.show', [$this->space, $expired->token]))->assertNotFound();
    }

    /**
     * The share page is served from the SPA origin, so Sanctum classifies its
     * requests as stateful and runs VerifyCsrfToken — while the anonymous
     * client sends no cookies and no CSRF token, which 419s every unlock.
     * VerifyCsrfToken short-circuits under runningUnitTests(), so the 419
     * cannot be reproduced here; guard the exemption pattern itself.
     */
    #[Test]
    public function share_endpoints_are_exempt_from_csrf(): void
    {
        $middleware = app(\App\Http\Middleware\VerifyCsrfToken::class);
        $inExceptArray = new \ReflectionMethod($middleware, 'inExceptArray');

        $request = \Illuminate\Http\Request::create(
            '/mgmt/v1/shares/'.$this->space->id.'/some-token/unlock',
            'POST',
        );

        $this->assertTrue($inExceptArray->invoke($middleware, $request));
    }

    #[Test]
    public function password_protected_shares_expose_only_a_minimal_shape_until_unlocked(): void
    {
        $share = $this->createShare([
            'description' => 'Top secret assets',
            'password' => Hash::make('open-sesame'),
        ]);

        $this->getJson(route('mgmt.shares.show', [$this->space, $share->token]))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'name' => 'Press kit',
                    'protected' => true,
                    'unlocked' => false,
                ],
            ]);

        $this->postJson(route('mgmt.shares.unlock', [$this->space, $share->token]), ['password' => 'wrong'])
            ->assertForbidden();

        $unlock = $this->postJson(route('mgmt.shares.unlock', [$this->space, $share->token]), ['password' => 'open-sesame'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'expires_at']);

        $accessToken = $unlock->json('access_token');

        $this->getJson(route('mgmt.shares.show', [$this->space, $share->token]), [
            'Authorization' => "Bearer {$accessToken}",
        ])
            ->assertOk()
            ->assertJsonPath('data.unlocked', true)
            ->assertJsonPath('data.description', 'Top secret assets');

        // The access token is share-bound: it must not unlock another share.
        $other = $this->createShare(['password' => Hash::make('different')]);
        $this->getJson(route('mgmt.shares.show', [$this->space, $other->token]), [
            'Authorization' => "Bearer {$accessToken}",
        ])
            ->assertOk()
            ->assertJsonPath('data.unlocked', false);
    }

    #[Test]
    public function it_lists_share_assets_in_a_limited_public_shape(): void
    {
        $asset = $this->createAssetWithFile('brochure');
        $share = $this->createShare(['asset_ids' => [$asset->id]]);

        $response = $this->getJson(route('mgmt.shares.assets', [$this->space, $share->token]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $asset->id)
            ->assertJsonPath('data.0.filename', 'brochure')
            ->assertJsonPath('data.0.mime_type', 'image/jpeg')
            ->assertJsonPath('meta.total', 1);

        $this->assertArrayNotHasKey('path', $response->json('data.0'));
        $this->assertArrayNotHasKey('checksum', $response->json('data.0'));
    }

    #[Test]
    public function download_returns_202_while_the_package_is_building(): void
    {
        Queue::fake();

        $asset = $this->createAssetWithFile();
        $share = $this->createShare(['asset_ids' => [$asset->id]]);

        $this->getJson(route('mgmt.shares.download', [$this->space, $share->token]))
            ->assertStatus(202)
            ->assertJsonPath('state', 'building');

        Queue::assertPushed(BuildAssetPackageJob::class);
    }

    #[Test]
    public function download_enforces_the_download_limit_atomically(): void
    {
        $this->configureCloudfrontSigning();

        $asset = $this->createAssetWithFile();
        $share = $this->createShare([
            'asset_ids' => [$asset->id],
            'download_limit' => 1,
        ]);

        $package = AssetPackage::create([
            'source_type' => 'selection',
            'asset_ids' => [$asset->id],
            'state' => AssetPackage::STATE_COMPLETED,
            's3_path' => "packages/{$this->space->id}/pkg/assets.zip",
            'asset_count' => 1,
            'progress' => 100,
            'expires_at' => now()->addDays(7),
        ]);
        $share->forceFill(['package_id' => $package->id])->save();

        $first = $this->getJson(route('mgmt.shares.download', [$this->space, $share->token]))->assertOk();

        $url = $first->json('url');
        $this->assertStringStartsWith("https://dl.test/dl/{$this->space->id}/pkg/assets.zip", $url);
        $this->assertStringContainsString('Signature=', $url);
        $this->assertStringContainsString('Key-Pair-Id=APKATESTTESTTEST', $url);

        $this->assertSame(1, $share->fresh()->download_count);

        $this->getJson(route('mgmt.shares.download', [$this->space, $share->token]))
            ->assertForbidden();
    }

    #[Test]
    public function individual_asset_downloads_respect_the_share_setting_and_asset_set(): void
    {
        $inside = $this->createAssetWithFile('inside');
        $outside = $this->createAssetWithFile('outside');

        $share = $this->createShare(['asset_ids' => [$inside->id]]);

        // Local driver has no temporary URLs → falls back to the public
        // ilum original URL.
        $this->getJson(route('mgmt.shares.assets.download', [$this->space, $share->token, $inside->id]))
            ->assertOk()
            ->assertJsonStructure(['url']);

        $this->getJson(route('mgmt.shares.assets.download', [$this->space, $share->token, $outside->id]))
            ->assertNotFound();

        $locked = $this->createShare([
            'asset_ids' => [$inside->id],
            'allow_individual_downloads' => false,
        ]);

        $this->getJson(route('mgmt.shares.assets.download', [$this->space, $locked->token, $inside->id]))
            ->assertForbidden();
    }

    #[Test]
    public function changing_the_password_invalidates_outstanding_access_tokens(): void
    {
        $asset = $this->createAssetWithFile();
        $share = $this->createShare([
            'asset_ids' => [$asset->id],
            'password' => Hash::make('open-sesame'),
        ]);

        $accessToken = $this->postJson(route('mgmt.shares.unlock', [$this->space, $share->token]), ['password' => 'open-sesame'])
            ->assertOk()
            ->json('access_token');

        $this->patchJson(route('mgmt.asset-shares.update', [$this->space, $share]), [
            'password' => 'rotated-secret',
        ])->assertOk();

        $this->getJson(route('mgmt.shares.show', [$this->space, $share->token]), [
            'Authorization' => "Bearer {$accessToken}",
        ])
            ->assertOk()
            ->assertJsonPath('data.unlocked', false);
    }

    #[Test]
    public function share_updates_enforce_a_consistent_source_definition_and_password_strength(): void
    {
        $asset = $this->createAssetWithFile();
        $share = $this->createShare(['asset_ids' => [$asset->id]]);

        // Switching the source type without the matching id must be rejected —
        // it would 500 the public endpoints and permanently fail builds.
        $this->patchJson(route('mgmt.asset-shares.update', [$this->space, $share]), [
            'source_type' => 'folder',
        ])->assertUnprocessable();

        $this->patchJson(route('mgmt.asset-shares.update', [$this->space, $share]), [
            'password' => 'a',
        ])->assertUnprocessable();
    }

    #[Test]
    public function failed_package_builds_are_not_redispatched_within_the_cooldown(): void
    {
        Queue::fake();

        $asset = $this->createAssetWithFile();
        $share = $this->createShare(['asset_ids' => [$asset->id]]);

        $failed = AssetPackage::create([
            'source_type' => 'selection',
            'asset_ids' => [$asset->id],
            'state' => AssetPackage::STATE_FAILED,
            'error' => 'boom',
        ]);
        $share->forceFill(['package_id' => $failed->id])->save();

        $this->getJson(route('mgmt.shares.download', [$this->space, $share->token]))
            ->assertStatus(202)
            ->assertJsonPath('state', 'failed');

        Queue::assertNotPushed(BuildAssetPackageJob::class);
        $this->assertSame(1, AssetPackage::query()->count());

        // Once the cooldown has passed, a single rebuild is dispatched.
        $failed->timestamps = false;
        $failed->forceFill(['updated_at' => now()->subHour()])->save();

        $this->getJson(route('mgmt.shares.download', [$this->space, $share->token]))
            ->assertStatus(202)
            ->assertJsonPath('state', 'building');

        Queue::assertPushed(BuildAssetPackageJob::class, 1);
        $this->assertSame(2, AssetPackage::query()->count());
    }

    #[Test]
    public function public_asset_listing_exposes_share_scoped_previews_instead_of_permanent_urls(): void
    {
        $asset = $this->createAssetWithFile('brochure');
        $share = $this->createShare(['asset_ids' => [$asset->id]]);

        $previewUrl = $this->getJson(route('mgmt.shares.assets', [$this->space, $share->token]))
            ->assertOk()
            ->json('data.0.preview_url');

        $this->assertStringContainsString("/shares/{$this->space->id}/{$share->token}/assets/{$asset->id}/preview", $previewUrl);

        // Revoking the share must cut previews off too — a permanent asset URL
        // would keep working forever.
        $share->forceFill(['revoked_at' => now()])->save();

        $this->get($previewUrl)->assertNotFound();
    }

    #[Test]
    public function revoke_endpoint_disables_public_access(): void
    {
        $share = $this->createShare();

        $this->getJson(route('mgmt.shares.show', [$this->space, $share->token]))->assertOk();

        $this->postJson(route('mgmt.asset-shares.revoke', [$this->space, $share]))
            ->assertOk()
            ->assertJsonPath('data.is_revoked', true);

        $this->getJson(route('mgmt.shares.show', [$this->space, $share->token]))->assertNotFound();
    }
}
