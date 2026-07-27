<?php

namespace Tests\Feature\Mgmt;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Avatars are written under a name the client chose and served straight off
 * the application origin, where the extension decides the content type.
 */
class AvatarUploadTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function the_stored_extension_comes_from_the_file_and_not_its_name(): void
    {
        Storage::fake('public');
        $user = User::factory()->create()->fresh();

        // Real PNG bytes under a .jpg name. A faked upload reports its type
        // from its filename, which is the very thing under test, so this has
        // to be a real file on disk.
        $path = tempnam(sys_get_temp_dir(), 'avatar').'.bin';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $this->actingAs($user)
            ->post(route('mgmt.users.me.avatar'), [
                'avatar' => new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true),
            ])
            ->assertSuccessful();

        $stored = $user->fresh()->avatar;

        $this->assertNotNull($stored);
        $this->assertStringEndsWith('.png', $stored);
        Storage::disk('public')->assertExists($stored);
    }

    #[Test]
    public function an_html_file_is_rejected_outright(): void
    {
        Storage::fake('public');
        $user = User::factory()->create()->fresh();

        $this->actingAs($user)
            ->postJson(route('mgmt.users.me.avatar'), [
                'avatar' => UploadedFile::fake()->createWithContent(
                    'avatar.html',
                    '<script>alert(1)</script>',
                ),
            ])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->avatar);
    }

    /**
     * The private disk holds every tenant's assets and backups and must never
     * be the one reachable over HTTP.
     */
    #[Test]
    public function avatars_are_written_to_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = User::factory()->create()->fresh();

        $this->actingAs($user)
            ->post(route('mgmt.users.me.avatar'), [
                'avatar' => UploadedFile::fake()->image('avatar.png', 10, 10),
            ])
            ->assertSuccessful();

        $stored = $user->fresh()->avatar;

        Storage::disk('public')->assertExists($stored);
        Storage::disk('local')->assertMissing($stored);
    }
}
