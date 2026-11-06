<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review data of a proposal that is not part of the change itself: the review
 * group, the model's reason, flags that keep it out of bulk acceptance, the
 * items it depends on and display labels. Asset operations use it first.
 * Applied via spaces:repair-databases on hosted deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ai_changeset_items', 'review')) {
            return;
        }

        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->longText('review')->nullable()->after('glossary_findings');
        });
    }

    public function down(): void
    {
        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->dropColumn('review');
        });
    }
};
