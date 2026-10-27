<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\Ai\ResolvedSkillResource;
use App\Models\Management\Space;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The skills a surface offers, so feature screens render them without hardcoding. */
class AiContextController extends Controller
{
    public function __invoke(Request $request, Space $space, SkillResolver $skills): AnonymousResourceCollection
    {
        $this->authorizeSpace($space, 'space.view');

        $validated = $request->validate([
            'surface' => ['required', 'string', 'max:64'],
            'block' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ResolvedSkillResource::collection($skills->forSurface($space, $validated['surface'], $validated['block'] ?? null));
    }
}
