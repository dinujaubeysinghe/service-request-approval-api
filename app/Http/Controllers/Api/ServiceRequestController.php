<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Requests\DecisionRequest;
use App\Http\Requests\ListServiceRequestsRequest;
use App\Http\Requests\StoreServiceRequestRequest;
use App\Http\Requests\UpdateServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class ServiceRequestController extends Controller
{
    public function index(ListServiceRequestsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $requests = ServiceRequest::query()
            ->visibleTo($user)
            ->with(['requester', 'assignee'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $like = '%'.$request->string('search')->toString().'%';

                $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderByDesc('id') // stable order, so pages never overlap
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return ServiceRequestResource::collection($requests);
    }

    public function store(StoreServiceRequestRequest $request, RequestWorkflow $workflow): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $created = $workflow->create(
            $user,
            $request->safe()->except('submit'),
            $request->boolean('submit'),
        );

        return (new ServiceRequestResource($created->load(['requester', 'manager', 'assignee'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        Gate::authorize('view', $serviceRequest);

        return new ServiceRequestResource($serviceRequest->load([
            'requester', 'manager', 'assignee', 'comments.author', 'activities.actor',
        ]));
    }

    public function update(UpdateServiceRequestRequest $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $workflow->update($serviceRequest, $request->validated(), $user);

        return new ServiceRequestResource($updated->load(['requester', 'manager', 'assignee']));
    }

    public function submit(Request $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): ServiceRequestResource
    {
        Gate::authorize('submit', $serviceRequest);

        /** @var User $user */
        $user = $request->user();

        $submitted = $workflow->transition($serviceRequest, RequestStatus::Submitted, $user);

        return new ServiceRequestResource($submitted->load(['requester', 'manager', 'assignee']));
    }

    public function decide(DecisionRequest $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $decided = $workflow->transition($serviceRequest, $request->target(), $user, $request->commentText());

        return new ServiceRequestResource($decided->load(['requester', 'manager', 'assignee']));
    }

    public function assign(AssignRequest $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $assigned = $workflow->transition(
            $serviceRequest,
            RequestStatus::Assigned,
            $user,
            $request->commentText(),
            $request->assignee(),
        );

        return new ServiceRequestResource($assigned->load(['requester', 'manager', 'assignee']));
    }

    public function changeStatus(ChangeStatusRequest $request, ServiceRequest $serviceRequest, RequestWorkflow $workflow): ServiceRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        $changed = $workflow->transition($serviceRequest, $request->target(), $user, $request->commentText());

        return new ServiceRequestResource($changed->load(['requester', 'manager', 'assignee']));
    }
}
