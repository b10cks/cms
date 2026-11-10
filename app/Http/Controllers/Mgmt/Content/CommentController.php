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
use App\Services\Audit\AuditActor;
use App\Services\People\PeopleDirectoryService;
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
        $this->authorize('viewAny', [Comment::class, $space]);

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
            // A thread reads oldest first. Without this the order is whatever
            // the database happens to return, which differs between runs.
            // Appended, so an explicit `sort` parameter still takes precedence
            // and this only breaks the ties.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return CommentResource::collection($comments);
    }

    /**
     * People who can be mentioned: everyone with access to the space.
     *
     * Open to anyone who can read the comments, while the people directory
     * needs space.members.view. So it gives names and avatars only.
     */
    public function mentionable(Space $space, Content $content, PeopleDirectoryService $people): JsonResponse
    {
        $this->authorize('view', [$content, $space]);
        $this->authorize('viewAny', [Comment::class, $space]);

        $members = $people->paginateForSpace($space, ['segment' => 'members', 'per_page' => PHP_INT_MAX])['data'];

        return response()->json([
            'data' => collect($members->items())
                ->map(fn (array $member) => [
                    'id' => $member['user']['id'],
                    'name' => $member['user']['name'],
                    'avatar' => $member['user']['avatar'],
                ])
                ->values(),
        ]);
    }

    /**
     * Create a root comment or reply
     */
    public function store(Space $space, Content $content, CreateCommentRequest $request): CommentResource
    {
        $this->authorize('view', [$content, $space]);
        $this->authorize('create', [Comment::class, $space]);

        $data = $request->validated();
        $data['content_id'] = $content->id;
        $data['author_id'] = auth()->id();

        $comment = new Comment();
        $comment->fill($data);
        $comment->withoutAudit();
        $comment->save();
        $comment->auditSpaceEvent('created', AuditActor::user($request->user()), ['content_id' => $content->id]);

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
        $this->authorize('viewAny', [Comment::class, $space]);

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

        $comment->delete();

        return response()->json(null, 204);
    }

    /**
     * Resolve a comment
     */
    public function resolve(Request $request, Space $space, Content $content, Comment $comment): CommentResource
    {
        $this->authorize('resolve', [$comment, $space]);

        $comment->withoutAudit();
        $comment->update(['resolved_at' => now()]);
        $comment->auditSpaceEvent('resolved', AuditActor::user($request->user()), ['content_id' => $content->id]);

        // is_resolved is generated from resolved_at by the database, so it only
        // reflects the write after a re-read.
        $comment->refresh();

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
    public function unresolve(Request $request, Space $space, Content $content, Comment $comment): CommentResource
    {
        $this->authorize('unresolve', [$comment, $space]);

        $comment->withoutAudit();
        $comment->update(['resolved_at' => null]);
        $comment->auditSpaceEvent('unresolved', AuditActor::user($request->user()), ['content_id' => $content->id]);

        // is_resolved is generated from resolved_at by the database, so it only
        // reflects the write after a re-read.
        $comment->refresh();

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
