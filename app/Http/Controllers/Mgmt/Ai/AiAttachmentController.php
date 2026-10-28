<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\UploadAiAttachmentRequest;
use App\Http\Resources\Management\Ai\AiAttachmentResource;
use App\Models\Management\Space;
use App\Models\Space\AiAttachment;
use App\Services\Ai\Attachments\AiAttachments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Uploads files for a message and serves them back to the people who may see them. */
class AiAttachmentController extends Controller
{
    public function store(UploadAiAttachmentRequest $request, Space $space, AiAttachments $attachments): JsonResponse
    {
        $attachment = $attachments->store($request->file('file'), $space, $request->user());

        return (new AiAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    /** The uploader, or anyone who can view the thread the file was sent in. Everyone else gets a 404. */
    public function show(Request $request, Space $space, string $attachment): Response
    {
        $model = AiAttachment::query()->where('space_id', $space->id)->with('message.conversation')->findOrFail($attachment);
        $thread = $model->message?->conversation;

        abort_unless(
            $model->user_id === $request->user()->id || ($thread !== null && $request->user()->can('view', [$thread, $space])),
            404,
        );

        return Storage::disk('transfers')->response($model->url, $model->name, [
            'Content-Type' => $model->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], $model->kind === AiAttachment::KIND_IMAGE ? 'inline' : 'attachment');
    }
}
