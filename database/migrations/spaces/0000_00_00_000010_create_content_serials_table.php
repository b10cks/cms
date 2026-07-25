<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_serials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id');
            $table->string('field_key', 100)->charset('ascii');

            // Numbering partition: which counter this number was drawn from.
            $table->string('scope_key', 191)->charset('ascii');

            // Uniqueness partition for the rendered value. Null when the field
            // enforces no uniqueness — NULLs never collide in a unique index on
            // any of the supported drivers.
            $table->string('unique_key', 191)->charset('ascii')->nullable();

            $table->unsignedBigInteger('number');
            $table->string('value', 191);

            $table->timestamps();

            // A live row is the reservation: it holds the number inside its
            // numbering scope and the rendered value inside its uniqueness
            // scope. Releasing a number (serial_gaps = reuse) deletes the row.
            $table->unique(['scope_key', 'number'], 'content_serials_scope_number_unique');
            $table->unique(['unique_key', 'value'], 'content_serials_unique_value_unique');
            $table->unique(['content_id', 'field_key']);
            $table->index(['field_key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_serials');
    }
};
