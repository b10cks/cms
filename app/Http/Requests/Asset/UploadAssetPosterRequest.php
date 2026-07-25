<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;

class UploadAssetPosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'poster' => [
                'required',
                'file',
                // The poster is served through the image pipeline, so it has to
                // be a raster format the driver can decode — SVG is excluded.
                'mimetypes:image/jpeg,image/png,image/webp,image/avif,image/gif',
                'max:'.(config('filesystems.max_upload_size', 500) * 1024),
            ],
        ];
    }
}
