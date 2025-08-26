<?php

namespace App\Services\Image;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

        $path = Storage::putFileAs(
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
        if ($model->{$attribute} && Storage::exists($model->{$attribute})) {
            Storage::delete($model->{$attribute});
        }
    }
}
