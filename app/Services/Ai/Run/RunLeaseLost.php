<?php

namespace App\Services\Ai\Run;

/** The run's lease expired and another worker took over: stop without writing. */
class RunLeaseLost extends RunBusy
{
    public function __construct()
    {
        parent::__construct('Another worker took over this AI run.');
    }
}
