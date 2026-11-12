<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\JsonResponse;

final class CommentController extends Controller
{
    public function store(StoreCommentRequest $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $comment = $workflow->addComment($serviceRequest, $user, $request->string('body')->toString());

        return (new CommentResource($comment->load('author')))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
