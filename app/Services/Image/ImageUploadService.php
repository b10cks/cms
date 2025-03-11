<?php

namespace App\Services\Image;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadService
{
    /**
     * Upload image for a model and return the file path.
     *
     * @param Model $model The model to attach the image to
     * @param UploadedFile $file The uploaded file
     * @param string $attribute The model attribute to update
     * @param string $directory The storage directory
     * @return string The path to the stored file
     */
    public function uploadForModel(Model $model, UploadedFile $file, string $attribute, string $directory): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = $model->getRouteKey() . '_' . time() . '.' . $extension;

        $path = Storage::disk('public')->putFileAs(
            $directory,
            $file,
            $filename
        );
        $this->deleteExistingFile($model, $attribute);

        $model->{$attribute} = $path;
        $model->save();

        return $path;
    }

    /**
     * Delete the existing file if it exists.
     *
     * @param Model $model The model with the file
     * @param string $attribute The attribute storing the file path
     * @return void
     */
    protected function deleteExistingFile(Model $model, string $attribute): void
    {
        if ($model->{$attribute} && Storage::disk('public')->exists($model->{$attribute})) {
            Storage::disk('public')->delete($model->{$attribute});
        }
    }

    /**
     * Get public URL for the stored file.
     *
     * @param string|null $path The file path
     * @return string|null The public URL or null if path is null
     */
    public function getPublicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
