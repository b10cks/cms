<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * @resourceProperty breadcrumb type=array items=ContentBreadcrumbResource Trail ordered from the tree root down to the requested entry.
 */
class ContentBreadcrumbCollection extends ResourceCollection
{
    /**
     * The trail is the response, not a page of records — `data` would say less
     * about it than `breadcrumb` does.
     */
    public static $wrap = 'breadcrumb';

    public $collects = ContentBreadcrumbResource::class;
}
