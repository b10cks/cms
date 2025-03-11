<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Filters\Api\BlockFilter;
use App\Http\Resources\Api\BlockResource;
use App\Models\Space\Block;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlockController extends Controller
{
    /**
     * @response AnonymousResourceCollection<BlockResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = Block::filter(BlockFilter::fromRequest($request))->get();

        return BlockResource::collection($data);
    }

    public function show(Block $block): BlockResource
    {
        return new BlockResource($block);
    }
}
