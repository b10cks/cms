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
     * List the block definitions of the space, including each block's schema,
     * type, and tags. Useful for type generation and dynamic rendering.
     *
     * @response AnonymousResourceCollection<BlockResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = Block::filter(BlockFilter::fromRequest($request))->get();

        return BlockResource::collection($data);
    }

    /**
     * Get a single block definition by ID.
     */
    public function show(Block $block): BlockResource
    {
        return new BlockResource($block);
    }
}
