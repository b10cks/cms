<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\CommentFilter;
use App\Http\Requests\Comment\CreateCommentRequest;
use App\Http\Requests\Comment\UpdateCommentRequest;
use App\Http\Resources\Management\CommentResource;
use App\Models\Management\Space;
use App\Models\Space\Comment;
use App\Models\Space\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class CommentController extends Controller
{
    /**
     * Get root comments for content
     */
    public function index(Space $space, Content $content, Request $request): ResourceCollection
    {
        $this->authorize('view', [$content, $space]);

        $comments = Comment::filter(CommentFilter::fromRequest($request))
            ->where('content_id', $content->id)
            ->whereNull('parent_id')
            ->with([
                'author',
                'replies.author',
                'replies.reactions.author',
                'reactions.author',
                'mentions',
                'replies.mentions',
            ])
            ->withCount(['replies', 'reactions'])
            ->get();

        return CommentResource::collection($comments);
    }

    /**
     * Create a root comment or reply
     */
    public function store(Space $space, Content $content, CreateCommentRequest $request): CommentResource
    {
        $this->authorize('view', [$content, $space]);

        $data = $request->validated();
        $data['content_id'] = $content->id;
        $data['author_id'] = auth()->id();

        $comment = Comment::create($data);

        return new CommentResource(
            $comment->load([
                'author',
                'parent',
                'replies.author',
                'replies.reactions.author',
                'reactions.author',
                'mentions',
                'replies.mentions',
            ])->loadCount(['replies', 'reactions'])
        );
    }

    /**
     * Get a specific comment (root or reply)
     */
    public function show(Space $space, Content $content, Comment $comment): CommentResource
    {
        $this->authorize('view', [$content, $space]);

        return new CommentResource(
            $comment->load([
                'author',
                'parent',
                'replies.author',
                'replies.reactions.author',
                'reactions.author',
                'mentions',
                'replies.mentions',
            ])->loadCount(['replies', 'reactions'])
        );
    }

    /**
     * Update a comment (root or reply)
     */
    public function update(Space $space, Content $content, UpdateCommentRequest $request, Comment $comment): CommentResource
    {
        $this->authorize('update', [$comment, $space]);

        $comment->fill($request->validated());

        if (!$comment->save()) {
            Log::error('Failed to update comment', ['comment_id' => $comment->id]);
            abort(500, 'Failed to update comment');
        }

        return new CommentResource(
            $comment->load([
                'author',
                'parent',
                'replies.author',
                'replies.reactions.author',
                'reactions.author',
                'mentions',
                'replies.mentions',
            ])->loadCount(['replies', 'reactions'])
        );
    }

    /**
     * Delete a comment (root or reply)
     */
    public function destroy(Space $space, Content $content, Comment $comment): JsonResponse
    {
        $this->authorize('delete', [$comment, $space]);

        try {
            $comment->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete comment', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the comment',
            ], 500);
        }
    }

    /**
     * Resolve a comment
     */
    public function resolve(Space $space, Content $content, Comment $comment): CommentResource
    {
        $this->authorize('resolve', [$comment, $space]);

        $comment->update(['resolved_at' => now()]);

        return new CommentResource($comment->load([
            'author',
            'parent',
            'replies.author',
            'replies.reactions.author',
            'reactions.author',
            'mentions',
            'replies.mentions',
        ])->loadCount(['replies', 'reactions']));
    }

    /**
     * Unresolve a comment
     */
    public function unresolve(Space $space, Content $content, Comment $comment): CommentResource
    {
        $this->authorize('unresolve', [$comment, $space]);

        $comment->update(['resolved_at' => null]);

        return new CommentResource($comment->load([
            'author',
            'parent',
            'replies.author',
            'replies.reactions.author',
            'reactions.author',
            'mentions',
            'replies.mentions',
        ])->loadCount(['replies', 'reactions']));
    }
}
