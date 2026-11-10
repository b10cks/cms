<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every knowledge document says in one line what it covers: runs list reference
 * documents by it. Existing documents start with their title.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_documents', function (Blueprint $table) {
            $table->string('description', 200)->default('');
        });

        DB::table('ai_knowledge_documents')->update(['description' => DB::raw('substr(title, 1, 200)')]);
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
