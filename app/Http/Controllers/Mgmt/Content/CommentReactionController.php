<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\CreateCommentReactionRequest;
use App\Http\Resources\Management\CommentReactionResource;
use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\Space\CommentReaction;
use App\Models\Space\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class CommentReactionController extends Controller
{
    public function index(Space $space, Content $content, Comment $comment): ResourceCollection
    {
        $this->authorize('view', [$content, $space]);

        $reactions = $comment->reactions()
            ->with('author')
            ->get();

        return CommentReactionResource::collection($reactions);
    }

    public function store(Space $space, Content $content, Comment $comment, CreateCommentReactionRequest $request): CommentReactionResource
    {
        $this->authorize('react', [$comment, $space]);

        $data = $request->validated();
        $data['comment_id'] = $comment->id;
        $data['author_id'] = auth()->id();

        $reaction = CommentReaction::firstOrCreate(
            [
                'comment_id' => $comment->id,
                'author_id' => auth()->id(),
                'emoji' => $data['emoji'],
            ],
            $data
        );

        return new CommentReactionResource($reaction->load('author'));
    }

    public function destroy(Space $space, Content $content, Comment $comment, CreateCommentReactionRequest $request): JsonResponse
    {
        $this->authorize('unreact', [$comment, $space]);
        $emoji = $request->input('emoji');

        try {
            CommentReaction::where('comment_id', $comment->id)
                ->where('author_id', auth()->id())
                ->where('emoji', $emoji)
                ->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete reaction', [
                'emoji' => $emoji,
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the reaction',
            ], 500);
        }
    }
}
