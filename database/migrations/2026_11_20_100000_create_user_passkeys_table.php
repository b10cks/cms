<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WebAuthn credentials (passkeys) a user signs in and confirms actions with.
 *
 * A credential id can be up to 1023 bytes, too long for a portable unique
 * index, so lookups go through `credential_hash` (SHA-256 of the raw id).
 * `record` holds the library's serialized credential record: public key,
 * signature counter, backup flags and the user handle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_passkeys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('credential_hash', 64)->charset('ascii')->unique();
            $table->text('record');
            $table->uuid('aaguid')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_passkeys');
    }
};
