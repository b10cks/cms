<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AuditLog hashes are HMAC-SHA256 hex (64 chars) since 6daa9d85; the column
 * was sized for bcrypt's fixed 60. Strict-mode MySQL rejected every audit
 * insert with 1406. Legacy $2y$ rows still fit and verify via the bcrypt
 * fallback, so widening is enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('hash', 64)->charset('ascii')->change();
        });
    }

    public function down(): void
    {
        // No shrink back to 60: it would truncate HMAC hashes.
    }
};
