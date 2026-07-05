<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_download_usage_hourly', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('hour_timestamp')->comment('Start of the hour');

            $table->unsignedBigInteger('bytes_sent')->default(0)->comment('Total bytes sent to clients');
            $table->unsignedBigInteger('request_count')->default(0)->comment('Number of download requests');

            $table->foreignUlid('space_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['space_id', 'hour_timestamp'], 'space_download_usage_hourly_unique');
            $table->index('hour_timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_download_usage_hourly');
    }
};
