<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\Schema\ContentSchemaValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateContent
{
    public function __construct(
        private readonly ContentI18nValidator $validator,
        private readonly ContentSchemaValidator $contentSchemaValidator,
    ) {
    }

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner)
    {
        $errors = $this->validator->validate($space, $data);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        \DB::transaction(function () use ($data, $content, $owner, $space) {
            if (!\Arr::has($data, 'language_iso')) {
                $data['language_iso'] = $space->settings->getDefaultLanguage();
            }

            $block = \App\Models\Space\Block::query()->findOrFail($data['block_id']);

            // Allow empty content submissions: if no content (null) or an empty array is provided,
            // skip schema validation and store an empty content payload.
            $submittedContent = data_get($data, 'content', null);

            if ($submittedContent === null || (is_array($submittedContent) && empty($submittedContent))) {
                $validatedContent = $submittedContent === null ? [] : $submittedContent;
            } else {
                $contentValidation = $this->contentSchemaValidator->validateSubmission(
                    $space,
                    $block,
                    $submittedContent,
                    null,
                    $data['language_iso'] ?? null,
                    $data['i18n_parent_id'] ?? null,
                );

                if (!$contentValidation->isValid()) {
                    throw ValidationException::withMessages($contentValidation->errors);
                }

                $validatedContent = $contentValidation->content;
            }

            unset($data['content']);
            $content->fill($data);

            $content->id = strtolower((string) Str::ulid());
            $version = ContentVersion::forceCreate([
                'content' => $validatedContent,
                'content_id' => $content->id,
                'created_by_id' => $owner->id,
            ]);
            $content->current_version_id = $version->id;
            $content->save();

            $space->touch('content_updated_at');
        });
    }
}
