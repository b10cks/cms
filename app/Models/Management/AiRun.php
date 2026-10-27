<?php

namespace App\Models\Management;

use App\Enums\AiRunState;
use App\Models\User;
use App\Services\Ai\Run\Transcript;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agent run and its audit: actor, surface, skill and frozen revisions,
 * model, state, transcript checkpoint, pending questionnaire, usage and result.
 *
 * @property string $id
 * @property string $space_id
 * @property string $actor_id
 * @property string|null $conversation_id
 * @property string|null $idempotency_key
 * @property string $surface
 * @property string|null $skill
 * @property string|null $ai_config_id
 * @property string|null $model
 * @property AiRunState $state
 * @property string $mode
 * @property array<string, mixed> $setup
 * @property array<string, mixed>|null $input
 * @property list<array<string, mixed>> $transcript
 * @property array<string, mixed>|null $questionnaire
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property bool $partial
 * @property bool $cancel_requested
 * @property int $steps
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost_usd
 * @property int $event_seq
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class AiRun extends GlobalModel
{
    use HasUlids;

    public const MODE_INLINE = 'inline';

    public const MODE_BACKGROUND = 'background';

    protected $fillable = [
        'space_id',
        'actor_id',
        'conversation_id',
        'idempotency_key',
        'surface',
        'skill',
        'ai_config_id',
        'model',
        'state',
        'mode',
        'setup',
        'input',
        'transcript',
        'questionnaire',
        'result',
        'error',
        'partial',
        'cancel_requested',
        'steps',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'event_seq',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'state' => AiRunState::class,
        'setup' => 'array',
        'input' => 'array',
        'transcript' => 'array',
        'questionnaire' => 'array',
        'result' => 'array',
        'partial' => 'boolean',
        'cancel_requested' => 'boolean',
        'steps' => 'integer',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_usd' => 'float',
        'event_seq' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiRunEvent::class, 'run_id');
    }

    public function transcriptObject(): Transcript
    {
        return Transcript::fromArray($this->transcript ?? []);
    }

    public function isFinished(): bool
    {
        return \in_array($this->state, [AiRunState::Completed, AiRunState::Failed, AiRunState::Cancelled], true);
    }
}
