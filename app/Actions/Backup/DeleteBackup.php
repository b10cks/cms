<?php

namespace App\Actions\Backup;

use App\Models\Management\SpaceBackup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteBackup
{
    public function execute(SpaceBackup $backup): void
    {
        if ($backup->s3_path && $backup->isActive()) {
            try {
                Storage::disk('transfers')->delete($backup->s3_path);
            } catch (\Exception $e) {
                Log::warning('Failed to delete backup file from S3', [
                    'backup_id' => $backup->id,
                    's3_path' => $backup->s3_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $backup->delete();
    }
}
