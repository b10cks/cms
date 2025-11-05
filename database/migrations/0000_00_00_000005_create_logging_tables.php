<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Audit Logs
        Schema::create('space_api_hits_hourly', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('hour_timestamp')->comment('Start of the hour');

            $table->unsignedBigInteger('hit_count')->default(0);
            $table->unsignedInteger('unique_ips')->default(0)->comment('Approximate unique IPs in this hour');
            $table->unsignedInteger('success_count')->default(0)->comment('2xx responses');
            $table->unsignedInteger('error_count')->default(0)->comment('4xx/5xx responses');

            $table->json('status_code_distribution')->nullable()->comment('Count per status code');
            $table->unsignedBigInteger('time_taken_sum')->default(0)->comment('Sum of time-taken in ms for requests in this hour');
            $table->unsignedBigInteger('time_taken')->storedAs('CASE WHEN `hit_count` = 0 THEN 0 ELSE `time_taken_sum` / `hit_count` END')->comment('Average time taken in ms per request');

            $table->foreignUlid('space_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['space_id', 'hour_timestamp'], 'space_api_hits_hourly_unique');
            $table->index('hour_timestamp');
        });

        Schema::create('space_traffic_usage_hourly', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('hour_timestamp')->comment('Start of the hour');

            $table->unsignedBigInteger('bytes_sent')->default(0)->comment('Total bytes sent to clients');
            $table->unsignedBigInteger('bytes_received')->default(0)->comment('Total bytes received from clients');
            $table->unsignedBigInteger('total_bytes')->storedAs('(`bytes_sent` + `bytes_received`)')->comment('Total bytes processed (sent + received)');
            $table->unsignedBigInteger('request_count')->default(0)->comment('Number of traffic requests');

            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('cache_misses')->default(0);

            $table->foreignUlid('space_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['space_id', 'hour_timestamp'], 'space_traffic_usage_hourly_unique');
            $table->index('hour_timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_api_hits_hourly');
        Schema::dropIfExists('space_traffic_usage_hourly');
    }
};
