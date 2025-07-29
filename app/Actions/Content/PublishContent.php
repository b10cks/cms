<?php

namespace App\Actions\Content;

use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class PublishContent
{
    public function execute(array $data, Content $content, Space $space, Authenticatable|User|null $owner)
    {
        \DB::transaction(function () use ($data, $content, $space, $owner) {
            $contentData = data_get($data, 'content');
            $message = data_get($data, 'message');
            unset($data['content']);
            unset($data['message']);
            $content->update($data);
            $content->load('current_version');

            if ($content->current_version?->content == $contentData) {
                $content->published_version_id = $content->current_version_id;
                ContentVersion::where('id', '=', $content->published_version_id)
                    ->where('content_id', $content->id)
                    ->update(['published_at' => now()]);
            } else {
                $version = ContentVersion::forceCreate([
                    'message' => $message,
                    'content_id' => $content->id,
                    'parent_id' => $content->current_version_id,
                    'content' => $contentData,
                    'created_by_id' => $owner->id,
                    'published_at' => now(),
                ]);
                $content->current_version_id = $version->id;
                $content->published_version_id = $version->id;
            }

            $content->setPublishedAt(now());
            $content->save();

            $space->touch('content_updated_at');
        });
    }
}
