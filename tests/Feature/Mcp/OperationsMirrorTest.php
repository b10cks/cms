<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Support\OperationRegistry;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsMirrorTest extends TestCase
{
    /** The CMS is ahead of the SDK here until @b10cks/mcp-server gets the content operations lifecycle and translation memory. */
    private const NOT_IN_THE_SDK_YET = '/^ai\.(operations|assets|translationMemory)\./';

    /** The CMS dropped the glossary; the SDK still has to drop its glossary operations and `lookup_glossary`. */
    private const ONLY_IN_THE_SDK = '/^ai\.(glossaries|teamGlossaries)\.|^lookup_glossary$/';

    #[Test]
    public function the_operation_registry_mirrors_the_sdk_mcp_server(): void
    {
        $package = env('B10CKS_SDK_PATH', base_path('../sdk')).'/packages/mcp-server';

        if (! is_file("{$package}/src/operations.ts")) {
            $this->markTestSkipped('The SDK checkout is not next to this repository.');
        }

        // Some SDK operations are generated in loops, so the names come from the loaded module.
        $result = Process::path($package)->run([
            'bun', '-e', "import { operations } from './src/operations.ts'; console.log(JSON.stringify(operations.map((o) => o.name)))",
        ]);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertEqualsCanonicalizing(
            array_values(preg_grep(self::NOT_IN_THE_SDK_YET, OperationRegistry::names(), PREG_GREP_INVERT)),
            array_values(preg_grep(self::ONLY_IN_THE_SDK, json_decode($result->output(), true), PREG_GREP_INVERT)),
        );
    }
}
