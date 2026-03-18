<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Content\ContentI18nValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateContent
{
    public function __construct(
        private readonly ContentI18nValidator $validator,
    ) {}

    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner)
    {
        $errors = $this->validator->validate($space, $data);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        \DB::transaction(function () use ($data, $content, $owner, $space) {
            $contentData = data_get($data, 'content');
            if (! \Arr::has($data, 'language_iso')) {
                $data['language_iso'] = $space->settings->getDefaultLanguage();
            }

            unset($data['content']);
            $content->fill($data);

            $content->id = strtolower((string) Str::ulid());
            $version = ContentVersion::forceCreate([
                'content' => $contentData,
                'content_id' => $content->id,
                'created_by_id' => $owner->id,
            ]);
            $content->current_version_id = $version->id;
            $content->save();

            $space->touch('content_updated_at');
        });
    }
}
