<?php

namespace Tests\Feature\Events;

use App\Events\ModelChangedEvent;
use App\Events\Space\ContentDeleted;
use App\Events\Space\ContentUpdated;
use App\Events\Space\SpaceModelChanged;
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
    public function space_model_changed_carries_a_materialised_data_payload(): void
    {
        $block = $this->makeBlock();

        $event = new SpaceModelChanged($this->space, 'blocks', 'updated', $block);

        $this->forgetSpaceConnection();
        $payload = unserialize(serialize($event))->broadcastWith();

        $this->assertSame($block->id, $payload['id']);
        $this->assertSame('updated', $payload['action']);
        $this->assertSame($block->id, $payload['data']['id']);
        $this->assertFullyMaterialised($payload);
    }

    #[Test]
    public function space_model_changed_deleted_carries_identifiers_only(): void
    {
        $event = new SpaceModelChanged($this->space, 'blocks', 'deleted', $this->makeBlock());

        $this->assertArrayNotHasKey('data', $event->broadcastWith());
    }

    /**
     * Reverb rejects messages above its size cap outright — a broadcast that
     * exceeds it silently reaches nobody. Better to drop the slim payload and
     * let the frontend fall back to invalidation.
     */
    #[Test]
    public function space_model_changed_drops_data_over_the_size_cap(): void
    {
        $block = Block::factory()->create(['description' => str_repeat('x', 20_000)]);

        $payload = (new SpaceModelChanged($this->space, 'blocks', 'updated', $block))->broadcastWith();

        $this->assertArrayNotHasKey('data', $payload);
        $this->assertSame($block->id, $payload['id']);
    }

    /**
     * broadcast(...)->toOthers() is a silent no-op unless the event uses
     * InteractsWithSockets — without it every save self-echoes and the
     * initiating client refetches its own caches.
     */
    #[Test]
    public function space_events_can_exclude_the_originating_socket(): void
    {
        $events = [
            SpaceModelChanged::class,
            ContentUpdated::class,
            ContentDeleted::class,
            \App\Events\Space\AssetCollectionContentChanged::class,
        ];

        foreach ($events as $event) {
            $this->assertContains(
                \Illuminate\Broadcasting\InteractsWithSockets::class,
                class_uses_recursive($event),
                $event.' must use InteractsWithSockets or toOthers() cannot exclude the sender.',
            );
        }
    }

    #[Test]
    public function data_source_content_changed_carries_identifiers_only(): void
    {
        $event = new \App\Events\Space\DataSourceContentChanged($this->space->id, 'ds-1');

        $this->assertSame(['id' => 'ds-1'], $event->broadcastWith());
        $this->assertSame('data_source:content_changed', $event->broadcastAs());
        $this->assertSame(
            'private-spaces.'.$this->space->id.'.data_sources',
            $event->broadcastOn()[0]->name,
        );
    }

    /**
     * Bulk operations mute the per-model broadcasts (a 1,000-row import must
     * not produce 1,000 events) and stand in for them with one
     * content-changed event; normal saves keep broadcasting afterwards.
     */
    #[Test]
    public function without_broadcasts_mutes_per_model_events_and_restores_after(): void
    {
        // Created first: the factory would otherwise create it inside the
        // muted block, and muting DataEntry deliberately leaves DataSource
        // broadcasting (the flag is per class).
        $dataSource = \App\Models\Space\DataSource::factory()->create();

        \Illuminate\Support\Facades\Event::fake([SpaceModelChanged::class]);

        \App\Models\Space\DataEntry::withoutBroadcasts(function () use ($dataSource) {
            \App\Models\Space\DataEntry::factory()->create(['data_source_id' => $dataSource->id]);
        });
        \Illuminate\Support\Facades\Event::assertNotDispatched(SpaceModelChanged::class);

        \App\Models\Space\DataEntry::factory()->create(['data_source_id' => $dataSource->id]);
        \Illuminate\Support\Facades\Event::assertDispatched(SpaceModelChanged::class);
    }

    #[Test]
    public function without_broadcasts_unmutes_even_when_the_callback_throws(): void
    {
        \Illuminate\Support\Facades\Event::fake([SpaceModelChanged::class]);

        try {
            \App\Models\Space\DataEntry::withoutBroadcasts(function (): void {
                throw new \RuntimeException('bulk operation failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        \App\Models\Space\DataEntry::factory()->create();
        \Illuminate\Support\Facades\Event::assertDispatched(SpaceModelChanged::class);
    }

    /**
     * Models without a Management resource (or whose resource cannot build
     * outside a real request) must still broadcast their identifiers.
     */
    #[Test]
    public function asset_collection_content_changed_carries_identifiers_only(): void
    {
        $event = new \App\Events\Space\AssetCollectionContentChanged($this->space->id, 'col-1');

        $this->assertSame(['id' => 'col-1'], $event->broadcastWith());
        $this->assertSame(
            'private-spaces.'.$this->space->id.'.assets',
            $event->broadcastOn()[0]->name,
        );
    }

    /**
     * Space channels carry full resource payloads since this branch — they
     * must ride private channels so the routes/channels.php guards apply.
     * The public share ping is the one deliberate exception (empty payload,
     * token-as-capability).
     */
    #[Test]
    public function space_events_broadcast_on_private_channels_only(): void
    {
        $content = $this->makeContentWithVersion();

        $events = [
            new SpaceModelChanged($this->space, 'blocks', 'updated', $this->makeBlock()),
            new ContentUpdated($content, $this->space),
            new ContentDeleted($content, $this->space),
            new \App\Events\Space\AssetCollectionContentChanged($this->space->id, 'col-1'),
        ];

        foreach ($events as $event) {
            foreach ($event->broadcastOn() as $channel) {
                $this->assertInstanceOf(
                    \Illuminate\Broadcasting\PrivateChannel::class,
                    $channel,
                    $event::class.' must broadcast privately.',
                );
            }
        }

        $ping = new \App\Events\Space\PublicAssetShareTouched($this->space->id, 'token-1');
        $this->assertNotInstanceOf(
            \Illuminate\Broadcasting\PrivateChannel::class,
            $ping->broadcastOn()[0],
        );
    }

    /**
     * `spaces.{space}.assets` has no subscription auth; the share resource
     * carries the share token and must never ride the broadcast.
     */
    #[Test]
    public function asset_share_broadcasts_without_its_resource_payload(): void
    {
        $share = \App\Models\Space\AssetShare::create([
            'token' => \App\Models\Space\AssetShare::generateToken(),
            'name' => 'Press kit',
            'source_type' => 'selection',
            'asset_ids' => [],
        ]);

        $payload = (new SpaceModelChanged($this->space, 'assets', 'updated', $share))->broadcastWith();

        $this->assertArrayNotHasKey('data', $payload);
        $this->assertSame($share->id, $payload['id']);
    }

    /**
     * The public share channel is unauthenticated by design — the token in the
     * channel name is the capability. That makes an empty payload the security
     * boundary: nothing about the share, its assets or its viewers may ride it.
     */
    #[Test]
    public function public_share_ping_carries_no_payload(): void
    {
        $event = new \App\Events\Space\PublicAssetShareTouched($this->space->id, 'tok_abc');

        $this->assertSame([], $event->broadcastWith());
        $this->assertSame('share:updated', $event->broadcastAs());
        $this->assertSame(
            'public-share.'.$this->space->id.'.tok_abc',
            $event->broadcastOn()[0]->name,
        );
    }

    #[Test]
    public function space_model_changed_carries_the_parent_context(): void
    {
        $entry = \App\Models\Space\DataEntry::factory()->create();

        $payload = (new SpaceModelChanged($this->space, 'data_sources', 'updated', $entry))->broadcastWith();

        $this->assertSame($entry->data_source_id, $payload['data_source_id']);
        $this->assertFullyMaterialised($payload);
    }

    #[Test]
    public function space_model_changed_survives_a_model_without_a_resource(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            public $incrementing = false;

            protected $keyType = 'string';
        };
        $model->id = 'model-without-resource';

        $payload = (new SpaceModelChanged($this->space, 'blocks', 'updated', $model))->broadcastWith();

        $this->assertSame('model-without-resource', $payload['id']);
        $this->assertArrayNotHasKey('data', $payload);
    }

    #[Test]
    public function content_updated_nested_translations_are_expanded_into_plain_data(): void
    {
        $content = $this->makeContentWithVersion();
        $translation = $this->makeTranslationOf($content);

        $payload = (new ContentUpdated($content->refresh(), $this->space))->broadcastWith();

        $this->assertIsArray($payload['i18n']);
        $this->assertCount(1, $payload['i18n']);
        $this->assertInstanceOf(\stdClass::class, $payload['i18n'][0]);
        $this->assertSame(
            $translation->id,
            $payload['i18n'][0]->id,
            'The nested translation must keep its data once expanded eagerly.',
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
        $translation = $this->makeTranslationOf($content);
        $event = new ContentUpdated($content->refresh(), $this->space);

        $this->forgetSpaceConnection();
        $payload = $this->payloadAfterQueueRoundTrip($event);

        // The nested translation is the part the worker would have to read back
        // off a connection it no longer has.
        $this->assertStringContainsString('"id":"' . $translation->id . '"', $payload);
    }

    /**
     * Guards the object-mode decode in ResolvesBroadcastPayload: an empty `{}`
     * decoded into an assoc array would go back on the wire as `[]` and change
     * the shape clients bind against.
     */
    #[Test]
    public function content_updated_keeps_empty_settings_as_an_object(): void
    {
        $content = $this->makeContentWithVersion();

        $payload = $this->payloadAfterQueueRoundTrip(new ContentUpdated($content, $this->space));

        $this->assertStringContainsString('"settings":{}', $payload);
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
        if ($path === 'payload') {
            // A payload of nothing but scalars would otherwise recurse without
            // asserting anything, and the test would pass by doing no work.
            $this->assertNotEmpty($payload, 'The broadcast payload must not be empty.');
        }

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

    /**
     * A translation of the given entry, which the menu payload carries nested
     * under `i18n`.
     */
    private function makeTranslationOf(Content $content): Content
    {
        return Content::factory()->create([
            'block_id' => $content->block_id,
            'i18n_parent_id' => $content->id,
            'language_iso' => 'de',
        ]);
    }

    private function makeBlock(): Block
    {
        return Block::factory()->create();
    }
}
