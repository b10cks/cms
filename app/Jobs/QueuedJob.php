<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class QueuedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        try {
            $this->execute();
            $this->handleCompletion();
        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    abstract protected function execute(): void;

    abstract protected function handleFailure(\Exception $e): void;

    protected function handleCompletion(): void
    {
        
    }
}
