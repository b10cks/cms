<?php

namespace Tests\Unit\Models\System;

use App\Models\System\AuditLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function it_verifies_integrity_of_unmodified_log()
    {
        $log = AuditLog::factory()->create([
            'action' => 'create',
            'entity_type' => 'App\Models\User',
            'entity_id' => '123',
            'new_values' => ['name' => 'John']
        ]);

        $this->assertTrue($log->verifyIntegrity());
        $log->assertIntegrity();
    }

    #[Test]
    public function it_detects_modified_log_content()
    {
        $log = AuditLog::factory()->create([
            'action' => 'create',
            'entity_type' => 'App\Models\User',
            'entity_id' => '123',
            'new_values' => ['name' => 'John']
        ]);

        // Modify the log directly in the database
        $log->forceFill([
            'old_values' => ['name' => 'Jane']
        ])->save();

        $this->assertFalse(AuditLog::first()->verifyIntegrity());
    }

    #[Test]
    public function it_detects_modified_log_id()
    {
        $log = AuditLog::factory()->create([
            'action' => 'create',
            'entity_type' => 'App\Models\User',
            'entity_id' => '123',
            'new_values' => ['name' => 'John']
        ]);

        $log->id = 'foo';

        $this->assertFalse($log->verifyIntegrity());
    }

    #[Test]
    public function it_throws_exception_on_integrity_assertion_failure()
    {
        $log = AuditLog::factory()->create([
            'action' => 'create',
            'entity_type' => 'App\Models\User',
            'entity_id' => '123'
        ]);

        $log->forceFill(['action' => 'update'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit log integrity check failed for ID: ' . $log->id);

        $log->assertIntegrity();
    }
}
