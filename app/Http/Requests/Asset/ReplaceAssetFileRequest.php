<?php

namespace App\Http\Requests\Asset;

use App\Http\Requests\Traits\RejectsActiveContentUploads;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceAssetFileRequest extends FormRequest
{
    use RejectsActiveContentUploads;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . (config('filesystems.max_upload_size', 500) * 1024),
                $this->rejectActiveContentRule(),
            ],
        ];
    }
}
