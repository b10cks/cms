<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a knowledge document keeps its row, revisions and chunks: policy
 * snapshots of approved work reference those revisions. Removing the owning space
 * or team still removes them through the existing cascades. Also adds the document's
 * optional language: a null `locale` keeps it in every run, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_documents', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('locale', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('locale');
            $table->dropSoftDeletes();
        });
    }
};
