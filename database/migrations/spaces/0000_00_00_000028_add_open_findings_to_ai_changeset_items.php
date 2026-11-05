<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many flagged wordings of a written item are still in its field, so the
     * content tree and thread status read flags without opening any document.
     * Items written before this column were accepted by a person, so none open.
     */
    public function up(): void
    {
        if (Schema::hasColumn('ai_changeset_items', 'open_findings')) {
            return;
        }

        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('open_findings')->default(0)->after('glossary_findings');
            $table->index(['changeset_id', 'open_findings']);
            $table->index(['open_findings', 'target_id']);
            $table->index(['status', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_changeset_items', function (Blueprint $table) {
            $table->dropIndex(['changeset_id', 'open_findings']);
            $table->dropIndex(['open_findings', 'target_id']);
            $table->dropIndex(['status', 'target_id']);
            $table->dropColumn('open_findings');
        });
    }
};
