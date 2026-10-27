<?php

namespace Tests\Feature\Ai;

use App\Models\Management\AiAttachment;
use App\Models\Management\AiConversation;
use App\Models\Management\AiMessage;
use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiAttachmentTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('transfers');
        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
    }

    #[Test]
    public function an_image_upload_is_stored_privately_and_an_unsupported_type_is_rejected(): void
    {
        $response = $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->image('logo.png', 40, 40)]);

        $response->assertCreated()->assertJsonPath('data.name', 'logo.png')->assertJsonPath('data.kind', 'image');
        $attachment = AiAttachment::query()->findOrFail($response->json('data.id'));
        Storage::disk('transfers')->assertExists($attachment->url);
        $this->assertStringStartsWith("ai-attachments/{$this->space->id}/", $attachment->url);
        $this->assertSame("/mgmt/v1/spaces/{$this->space->id}/ai/attachments/{$attachment->id}", $response->json('data.url'));

        $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->create('run.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    #[Test]
    public function a_run_sends_images_as_parts_and_documents_as_fenced_text(): void
    {
        $image = $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->image('logo.png', 40, 40)])->json('data.id');
        $csv = $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->createWithContent('prices.csv', "sku,price\nA1,9\n")])->json('data.id');
        $this->driver->push(FakeAiDriver::text('Seen.'));

        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'What is attached?', 'attachments' => [$image, $csv]])->streamedContent();

        $content = collect($this->driver->transcripts[0])->firstWhere('role', 'user')['content'];
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('image/png', $content[1]['mime_type']);
        $this->assertMatchesRegularExpression('/<document-(\w{8}) name="prices.csv">\nsku,price\nA1,9\n<\/document-\1>/', $content[0]['text']);
        $this->assertSame(2, AiMessage::query()->firstOrFail()->attachments()->count());
    }

    #[Test]
    public function a_follow_up_remembers_file_names_but_not_contents(): void
    {
        $id = $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->createWithContent('brief.txt', 'secret plan')])->json('data.id');
        $this->driver->push(FakeAiDriver::text('Read it.'), FakeAiDriver::text('Sure.'));

        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'attachments' => [$id]])->streamedContent();
        $thread = AiConversation::query()->firstOrFail();
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'And now?', 'conversation_id' => $thread->id])->streamedContent();

        $earlier = collect($this->driver->transcripts[1])->where('role', 'user')->first()['content'];
        $this->assertSame('[attached: brief.txt]', $earlier);
    }

    #[Test]
    public function someone_elses_and_already_sent_attachments_fail_validation(): void
    {
        $foreign = AiAttachment::create(['space_id' => $this->space->id, 'user_id' => User::factory()->create()->id, 'name' => 'x.txt', 'mime_type' => 'text/plain', 'size' => 1, 'url' => 'ai-attachments/x.txt']);
        $mine = $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'a')])->json('data.id');
        $this->driver->push(FakeAiDriver::text('ok'));
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'attachments' => [$mine]])->streamedContent();

        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'attachments' => [$foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'attachments' => [$mine]])->assertUnprocessable()->assertJsonValidationErrors('attachments');
    }

    #[Test]
    public function the_file_is_served_to_the_uploader_and_thread_viewers_only(): void
    {
        $id = $this->postJson($this->url('ai/attachments'), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'hello')])->json('data.id');
        $this->driver->push(FakeAiDriver::text('ok'));
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'attachments' => [$id]])->streamedContent();
        $thread = AiConversation::query()->firstOrFail();

        $other = User::factory()->create();
        $this->assignSpaceRole($this->space, $other, 'owner');

        $this->getJson($this->url("ai/attachments/{$id}"))->assertOk();
        $this->actingAs($other)->getJson($this->url("ai/attachments/{$id}"))->assertNotFound();

        $thread->forceFill(['shared' => true])->save();
        $this->actingAs($other)->get($this->url("ai/attachments/{$id}"))->assertOk();
        $this->actingAs($this->user)->getJson($this->url("ai/threads/{$thread->id}"))->assertJsonPath('data.turns.0.attachments.0.name', 'a.txt');
    }

    #[Test]
    public function the_thread_list_counts_settled_threads(): void
    {
        foreach ([null, now()] as $archivedAt) {
            AiConversation::create(['space_id' => $this->space->id, 'user_id' => $this->user->id, 'surface' => 'cmdk', 'title' => 'T', 'last_activity_at' => now(), 'archived_at' => $archivedAt]);
        }

        $this->getJson($this->url('ai/threads'))->assertJsonPath('meta.settled', 1);
    }

    #[Test]
    public function pruning_removes_only_old_unsent_attachments(): void
    {
        $make = fn (string $name, ?string $messageId, int $hoursOld) => tap(AiAttachment::create([
            'space_id' => $this->space->id, 'user_id' => $this->user->id, 'message_id' => $messageId, 'name' => $name, 'mime_type' => 'text/plain', 'size' => 1, 'url' => "ai-attachments/{$name}",
        ]), function (AiAttachment $attachment) use ($hoursOld) {
            Storage::disk('transfers')->put($attachment->url, 'x');
            $attachment->forceFill(['created_at' => now()->subHours($hoursOld)])->save();
        });
        $message = AiMessage::create(['conversation_id' => AiConversation::create(['space_id' => $this->space->id, 'user_id' => $this->user->id, 'surface' => 'cmdk', 'title' => 'T', 'last_activity_at' => now()])->id, 'role' => 'user', 'content' => 'hi']);
        $old = $make('old.txt', null, 30);
        $fresh = $make('fresh.txt', null, 2);
        $sent = $make('sent.txt', $message->id, 30);

        $this->artisan('ai:prune-attachments')->assertSuccessful();

        $this->assertModelMissing($old);
        Storage::disk('transfers')->assertMissing($old->url);
        $this->assertModelExists($fresh);
        $this->assertModelExists($sent);
        Storage::disk('transfers')->assertExists($sent->url);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
