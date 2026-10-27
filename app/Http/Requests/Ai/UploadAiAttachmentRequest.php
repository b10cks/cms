<?php

namespace App\Http\Requests\Ai;

use App\Models\Management\AiRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadAiAttachmentRequest extends FormRequest
{
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'md', 'txt', 'csv'];

    public function authorize(): bool
    {
        return $this->user()?->can('attach', [AiRun::class, $this->route('space')]) ?? false;
    }

    public function rules(): array
    {
        $images = (int) config('ai.attachments.image_kb');
        $documents = (int) config('ai.attachments.document_kb');

        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', [...self::IMAGE_EXTENSIONS, ...self::DOCUMENT_EXTENSIONS]),
                'max:'.max($images, $documents),
                fn (string $attribute, mixed $file, \Closure $fail) => $file instanceof UploadedFile
                    && str_starts_with((string) $file->getMimeType(), 'image/')
                    && $file->getSize() > $images * 1024
                    && $fail(__('Images can be up to :size MB.', ['size' => $images / 1024])),
            ],
        ];
    }
}
