<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PersonResource;
use App\Models\Management\Team;
use App\Services\People\PeopleDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TeamPeopleController extends Controller
{
    public function __construct(private readonly PeopleDirectoryService $directory)
    {
    }

    public function index(Request $request, Team $team): ResourceCollection
    {
        $this->authorize('viewMembers', $team);

        $result = $this->directory->paginateForTeam($team, $request->all());
        $result['data']->appends($request->query());

        return PersonResource::collection($result['data'])
            ->additional(['counts' => $result['counts']]);
    }
}
