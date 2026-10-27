<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Support\OperationRegistry;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsMirrorTest extends TestCase
{
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
        $this->assertEqualsCanonicalizing(OperationRegistry::names(), json_decode($result->output(), true));
    }
}
