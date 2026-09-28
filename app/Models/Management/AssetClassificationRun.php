<?php

namespace App\Models\Management;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

class AssetClassificationRun extends GlobalModel
{
    use HasUlids;

    protected $fillable = ['space_id', 'queued', 'updated', 'skipped', 'failed', 'enqueuing'];

    protected $casts = [
        'queued' => 'integer',
        'updated' => 'integer',
        'skipped' => 'integer',
        'failed' => 'integer',
        'enqueuing' => 'boolean',
    ];

    public function record(string $outcome): void
    {
        if (! \in_array($outcome, ['updated', 'skipped', 'failed'], true)) {
            throw new \InvalidArgumentException('Unknown classification outcome.');
        }

        $this->increment($outcome);
    }

    public function progress(): array
    {
        $pending = max(0, $this->queued - $this->updated - $this->skipped - $this->failed);

        return [
            'run_id' => $this->id,
            'queued' => $this->queued,
            'pending' => $pending,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'complete' => ! $this->enqueuing && $pending === 0,
        ];
    }
}
