<?php

namespace App\Services\Ai\Run;

use RuntimeException;

/**
 * A tool failure the model can act on (validation, not found, forbidden). The
 * run service returns it to the model as the tool result; any other exception
 * is an infrastructure failure.
 */
class ToolError extends RuntimeException {}
