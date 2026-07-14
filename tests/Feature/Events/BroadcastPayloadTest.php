<?php

namespace Tests\Feature\Events;

use App\Events\ModelChangedEvent;
use App\Events\Space\ContentDeleted;
use App\Events\Space\ContentUpdated;
use App\Http\Resources\Management\BlockResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Models\Space\Content;
use App\Services\Database\SpaceModelResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/**
 * Broadcast payloads are encoded by the queue worker, where no space connection
 * is bound and space models can no longer be read. Every event therefore has to
 * hand the queue plain data: a resource left anywhere in the payload only
 * expands once Pusher encodes it, and blows up with "Space not found".
 *
 * JsonResource::resolve() expands only the outermost resource, so the nesting is
 * what these tests guard.
 */
class BroadcastPayloadTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->withLive()->create();
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function content_updated_payload_contains_no_unresolved_resources(): void
    {
        $event = new ContentUpdated($this->makeContentWithVersion(), $this->space);

        $this->assertFullyMaterialised($event->broadcastWith());
    }

    #[Test]
    public function content_deleted_payload_contains_no_unresolved_resources(): void
    {
        $content = $this->makeContentWithVersion();
        $content->delete();

        $event = new ContentDeleted($content, $this->space);

        $this->assertFullyMaterialised($event->broadcastWith());
    }

    #[Test]
    public function model_changed_payload_contains_no_unresolved_resources(): void
    {
        $block = $this->makeBlock();
        $block->setRelation('folder', BlockFolder::factory()->create());

        $event = new ModelChangedEvent($block, BlockResource::class, 'updated');

        $this->assertFullyMaterialised($event->broadcastWith());
    }

    #[Test]
    public function content_updated_nested_version_is_expanded_into_plain_data(): void
    {
        $content = $this->makeContentWithVersion();

        $payload = (new ContentUpdated($content, $this->space))->broadcastWith();

        $this->assertInstanceOf(\stdClass::class, $payload['current_version']);
        $this->assertSame(
            $content->current_version_id,
            $payload['current_version']->id,
            'The nested version must keep its data once expanded eagerly.',
        );
    }

    /**
     * The closest reproduction of the production failure: the event goes through
     * the queue and is encoded with the space connection gone, exactly as the
     * worker does it.
     */
    #[Test]
    public function content_updated_payload_encodes_once_the_space_connection_is_gone(): void
    {
        $content = $this->makeContentWithVersion();
        $event = new ContentUpdated($content, $this->space);

        $this->forgetSpaceConnection();
        $payload = $this->payloadAfterQueueRoundTrip($event);

        $this->assertStringContainsString('"id":"' . $content->current_version_id . '"', $payload);
    }

    /**
     * Guards the object-mode decode in ResolvesBroadcastPayload: an empty `{}`
     * decoded into an assoc array would go back on the wire as `[]` and change
     * the shape clients bind against.
     */
    #[Test]
    public function content_updated_keeps_empty_content_as_an_object(): void
    {
        $content = $this->makeContentWithVersion(versionContent: []);

        $payload = $this->payloadAfterQueueRoundTrip(new ContentUpdated($content, $this->space));

        $this->assertStringContainsString('"content":{}', $payload);
    }

    /**
     * Put the resolver into the state a queue worker is in: no space bound, so
     * reading any space model blows up. The real resolver short-circuits to the
     * default connection under runningUnitTests(), which would otherwise let a
     * lazy expansion succeed here and hide the very bug under test.
     */
    private function forgetSpaceConnection(): void
    {
        app()->offsetUnset('currentSpace');
        app()->instance(SpaceModelResolver::class, new class extends SpaceModelResolver
        {
            public function getDefaultConnection(): never
            {
                throw new NotFoundHttpException('Space not found');
            }
        });
    }

    /**
     * Serialize/unserialize is what the queue does to the event; json_encode is
     * what Pusher does to broadcastWith() on the far side.
     */
    private function payloadAfterQueueRoundTrip(object $event): string
    {
        $restored = unserialize(serialize($event));

        return json_encode($restored->broadcastWith(), JSON_THROW_ON_ERROR);
    }

    /**
     * A fully materialised payload holds scalars, arrays and stdClass only.
     * Anything else — a resource, a model, a Carbon — is a lazy expansion the
     * worker would have to perform against a connection it does not have.
     */
    private function assertFullyMaterialised(mixed $payload, string $path = 'payload'): void
    {
        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $this->assertFullyMaterialised($value, $path . '.' . $key);
            }

            return;
        }

        if (! is_object($payload)) {
            return;
        }

        $this->assertNotInstanceOf(
            JsonResource::class,
            $payload,
            sprintf('%s is an unresolved %s and would expand on the queue worker.', $path, $payload::class),
        );
        $this->assertSame(
            \stdClass::class,
            $payload::class,
            sprintf('%s must be plain data by the time it reaches the queue.', $path),
        );

        foreach (get_object_vars($payload) as $key => $value) {
            $this->assertFullyMaterialised($value, $path . '.' . $key);
        }
    }

    private function makeContentWithVersion(array $versionContent = ['title' => 'Hello']): Content
    {
        $content = Content::factory()->create(['block_id' => $this->makeBlock()->id]);
        $content->current_version->forceFill(['content' => $versionContent])->save();

        return $content->refresh();
    }

    private function makeBlock(): Block
    {
        return Block::factory()->create();
    }
}
