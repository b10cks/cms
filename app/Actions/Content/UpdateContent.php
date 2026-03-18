<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\ContentI18nValidator;
use App\Services\Content\Schema\ContentSchemaValidator;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class UpdateContent
{
    public function __construct(
        protected SearchService $searchService,
        protected ContentI18nValidator $validator,
        protected ContentSchemaValidator $contentSchemaValidator,
    ) {}

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner)
    {
        $errors = $this->validator->validate($space, $data, $content);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $wasPublished = $content->published_at !== null;
        $content->loadMissing('block');
        $contentValidation = $this->contentSchemaValidator->validateSubmission(
            $space,
            $content->block,
            data_get($data, 'content', []),
            $content,
            $data['language_iso'] ?? $content->language_iso,
            $data['i18n_parent_id'] ?? $content->i18n_parent_id,
        );

        if (! $contentValidation->isValid()) {
            throw ValidationException::withMessages($contentValidation->errors);
        }

        \DB::transaction(function () use ($data, $content, $space, $owner, $contentValidation) {
            $contentData = $contentValidation->content;
            $message = data_get($data, 'message');

            unset($data['content']);
            unset($data['message']);
            $content->update($data);
            $content->load('current_version');

            if ($content->current_version?->content != $contentData) {
                $version = ContentVersion::forceCreate([
                    'message' => $message,
                    'content_id' => $content->id,
                    'parent_id' => $content->current_version_id,
                    'content' => $contentData,
                    'created_by_id' => $owner->id,
                ]);
                $content->current_version_id = $version->id;
                $content->published_at = null;
            }
            $content->save();

            $space->touch('content_updated_at');
        });

        if ($wasPublished && ($content->published_at === null)) {
            $this->searchService->removeContent($content, $space);
        }
    }
}
