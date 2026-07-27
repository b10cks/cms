<?php

namespace App\Services\Image;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageUploadService
{
    /**
     * Avatars and icons are public by nature and are served as `/storage/...`.
     *
     * They therefore belong on the disk that is meant to be reachable over
     * HTTP. Writing them to the default disk put them in `storage/app/private`
     * alongside every tenant's assets and backups, which only worked if the
     * `public/storage` symlink pointed at that private root — exposing the
     * whole disk to anyone who knew a path.
     */
    private const DISK = 'public';

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
        $filename = $model->getRouteKey() . '_' . time() . '.' . $this->extensionFor($file);

        $path = Storage::disk(self::DISK)->putFileAs(
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
     * Remove the file an attribute points at and clear the attribute.
     */
    public function removeForModel(Model $model, string $attribute): void
    {
        $this->deleteExistingFile($model, $attribute);

        $model->{$attribute} = null;
        $model->save();
    }

    /**
     * The extension to store the file under, derived from its contents.
     *
     * `mimes:` validates the type guessed from the bytes, but the filename is
     * the client's. Storing under the client's extension meant a genuine GIF
     * called `avatar.html` was written as `.html`, and a web server picks the
     * content type from the extension — so a GIF/HTML polyglot would have been
     * served as a document on the application's own origin.
     */
    private function extensionFor(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->extension() ?: $file->guessExtension()));

        // Nothing recognizable in the bytes: keep the file rather than lose it,
        // but under an extension no server will execute or render inline.
        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
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
        $disk = Storage::disk(self::DISK);

        if ($model->{$attribute} && $disk->exists($model->{$attribute})) {
            $disk->delete($model->{$attribute});
        }
    }
}
