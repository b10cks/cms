<?php

namespace App\Http\Requests\Asset;

use App\Services\Storage\AssetService;
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
                'mimetypes:'.implode(',', array_keys(AssetService::POSTER_EXTENSIONS)),
                'max:'.(config('filesystems.max_upload_size', 500) * 1024),
            ],
        ];
    }
}
