<?php

namespace App\Services\Ai\Run;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Another request is already advancing or resuming the run. */
class RunBusy extends ConflictHttpException
{
    public function __construct(string $message = 'This AI run is already being processed.')
    {
        parent::__construct($message);
    }
}
