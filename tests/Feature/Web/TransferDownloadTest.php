<?php

namespace Tests\Feature\Web;

use App\Services\Asset\ShareDeliveryService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransferDownloadTest extends TestCase
{
    #[Test]
    public function local_transfers_disk_yields_a_signed_app_route(): void
    {
        config(['filesystems.disks.transfers.driver' => 'local']);

        $url = app(ShareDeliveryService::class)
            ->transferDownloadUrl('packages/space/pkg/archive.zip', now()->addMinutes(10));

        $this->assertStringContainsString('/transfers/download', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    #[Test]
    public function signed_route_streams_the_file(): void
    {
        config(['filesystems.disks.transfers.driver' => 'local']);
        Storage::fake('transfers');
        Storage::disk('transfers')->put('packages/a/b/archive.zip', 'zip-bytes');

        $url = URL::temporarySignedRoute('transfers.download', now()->addMinutes(5), [
            'path' => 'packages/a/b/archive.zip',
        ]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=archive.zip');
        $this->assertSame('zip-bytes', $response->streamedContent());
    }

    #[Test]
    public function unsigned_or_tampered_requests_are_rejected(): void
    {
        Storage::fake('transfers');
        Storage::disk('transfers')->put('packages/a/b/archive.zip', 'zip-bytes');

        $this->get('/transfers/download?path=packages/a/b/archive.zip')->assertForbidden();

        $url = URL::temporarySignedRoute('transfers.download', now()->addMinutes(5), [
            'path' => 'packages/a/b/archive.zip',
        ]);
        $this->get(str_replace('archive.zip', 'other.zip', $url))->assertForbidden();
    }

    #[Test]
    public function missing_files_return_404(): void
    {
        Storage::fake('transfers');

        $url = URL::temporarySignedRoute('transfers.download', now()->addMinutes(5), [
            'path' => 'packages/nope.zip',
        ]);

        $this->get($url)->assertNotFound();
    }
}
