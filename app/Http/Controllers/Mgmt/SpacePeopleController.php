<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PersonResource;
use App\Models\Management\Space;
use App\Services\People\PeopleDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SpacePeopleController extends Controller
{
    public function __construct(private readonly PeopleDirectoryService $directory)
    {
    }

    public function index(Request $request, Space $space): ResourceCollection
    {
        $this->authorize('viewMembers', $space);

        $result = $this->directory->paginateForSpace($space, $request->all());
        $result['data']->appends($request->query());

        return PersonResource::collection($result['data'])
            ->additional(['counts' => $result['counts']]);
    }
}
