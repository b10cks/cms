<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * A provider call failed. Its outcome is ambiguous (the provider may have
 * billed it), so the run service fails the run instead of retrying.
 */
class ProviderCallFailed extends RuntimeException {}
