<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delivery-API tokens now enforce their `abilities` column. Everything
 * issued before enforcement could read every resource and every version
 * scope (including drafts), so tokens keep exactly that surface:
 * empty abilities become `*:read` and every token gains `*:preview`
 * unless a preview grant was already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tokens')->orderBy('id')->chunkById(500, function ($tokens) {
            foreach ($tokens as $token) {
                $abilities = json_decode($token->abilities ?? '', true);
                $abilities = is_array($abilities) ? array_values($abilities) : [];

                if ($abilities === []) {
                    $abilities = ['*:read'];
                }

                $hasPreview = array_filter(
                    $abilities,
                    fn ($ability) => $ability === 'preview' || str_ends_with((string) $ability, ':preview'),
                );

                if ($hasPreview === []) {
                    $abilities[] = '*:preview';
                }

                DB::table('tokens')
                    ->where('id', $token->id)
                    ->update(['abilities' => json_encode($abilities)]);
            }
        });
    }

    public function down(): void
    {
        // Data backfill; the widened grants are the pre-enforcement status quo
        // and must survive a rollback of the code that added enforcement.
    }
};
