<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ServiceRequest;
use App\Models\User;

final class ServiceRequestPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(Role::Employee);
    }

    public function view(User $user, ServiceRequest $request): bool
    {
        return match ($user->role) {
            Role::Employee => $this->isRequester($user, $request),
            Role::Manager => $this->isApprover($user, $request),
            Role::Operations => $this->isInOperationsQueue($request),
        };
    }

    public function update(User $user, ServiceRequest $request): bool
    {
        return $this->canActAsRequester($user, $request)
            && $request->status->isEditable();
    }

    public function submit(User $user, ServiceRequest $request): bool
    {
        return $this->canActAsRequester($user, $request);
    }

    /** Approve, reject or return. */
    public function decide(User $user, ServiceRequest $request): bool
    {
        return $user->hasRole(Role::Manager)
            && $this->isApprover($user, $request)
            && ! $this->isRequester($user, $request);
    }

    public function assign(User $user, ServiceRequest $request): bool
    {
        return $user->hasRole(Role::Operations);
    }

    /** Move to in progress or completed. */
    public function changeStatus(User $user, ServiceRequest $request): bool
    {
        return $user->hasRole(Role::Operations);
    }

    public function comment(User $user, ServiceRequest $request): bool
    {
        return $this->view($user, $request);
    }

    private function canActAsRequester(User $user, ServiceRequest $request): bool
    {
        return $user->hasRole(Role::Employee) && $this->isRequester($user, $request);
    }

    private function isRequester(User $user, ServiceRequest $request): bool
    {
        return $request->requester_id === $user->id;
    }

    /** The approver is the manager snapshotted at submit time. */
    private function isApprover(User $user, ServiceRequest $request): bool
    {
        return $request->manager_id === $user->id;
    }

    private function isInOperationsQueue(ServiceRequest $request): bool
    {
        return in_array($request->status, RequestStatus::visibleToOperations(), true);
    }
}
