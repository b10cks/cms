<?php

namespace App\Actions\Release;

use App\Jobs\Release\PublishScheduledReleaseJob;
use App\Models\Management\Space;
use App\Models\Space\Release;

class CommitRelease
{
    public function execute(Release $release, Space $space): void
    {
        if ($release->publish_at === null) {
            return;
        }

        PublishScheduledReleaseJob::dispatch(
            $space->id,
            $release->id,
        )->delay($release->publish_at);
    }
}
