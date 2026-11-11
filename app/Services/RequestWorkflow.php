<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Exceptions\InvalidTransitionException;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class RequestWorkflow
{
    /**
     * Move a request to a new status, record who did it and return the updated request.
     *
     * Authorisation ("may this user do this?") is the policy's job. This class only
     * answers "may the request move from its current status to $to?".
     */
    public function transition(
        ServiceRequest $request,
        RequestStatus $to,
        User $actor,
        ?string $comment = null,
        ?User $assignee = null,
    ): ServiceRequest {
        return DB::transaction(function () use ($request, $to, $actor, $comment, $assignee): ServiceRequest {
            // Lock the row and re-read it, so the check below uses the latest status.
            $locked = ServiceRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidTransitionException::between($from, $to);
            }

            $changes = $this->changesFor($locked, $to, $assignee);

            $locked->update(['status' => $to] + $changes);

            RequestActivity::create([
                'service_request_id' => $locked->id,
                'actor_id' => $actor->id,
                'action' => $this->actionFor($to),
                'from_status' => $from,
                'to_status' => $to,
                'comment' => $comment,
                'metadata' => $to === RequestStatus::Assigned
                    ? ['assignee_id' => $changes['assignee_id']]
                    : null,
            ]);

            return $locked;
        });
    }

    /**
     * The extra columns each target status sets.
     *
     * @return array<string, mixed>
     */
    private function changesFor(ServiceRequest $request, RequestStatus $to, ?User $assignee): array
    {
        $now = now();

        return match ($to) {
            RequestStatus::Submitted => [
                // Snapshot the approver on every (re)submit.
                'manager_id' => $request->requester->manager_id
                    ?? throw InvalidTransitionException::missingManager(),
                'submitted_at' => $now,
            ],
            RequestStatus::Approved,
            RequestStatus::Rejected,
            RequestStatus::Returned => ['decided_at' => $now],
            RequestStatus::Assigned => [
                'assignee_id' => $this->operationsUserId($assignee),
                'assigned_at' => $now,
            ],
            RequestStatus::InProgress => ['started_at' => $now],
            RequestStatus::Completed => ['completed_at' => $now],
            RequestStatus::Draft => [],
        };
    }

    private function operationsUserId(?User $assignee): int
    {
        if ($assignee === null || ! $assignee->hasRole(Role::Operations)) {
            throw new InvalidArgumentException('A request can only be assigned to an Operations user.');
        }

        return $assignee->id;
    }

    private function actionFor(RequestStatus $to): ActivityAction
    {
        return match ($to) {
            RequestStatus::Submitted => ActivityAction::Submitted,
            RequestStatus::Approved => ActivityAction::Approved,
            RequestStatus::Rejected => ActivityAction::Rejected,
            RequestStatus::Returned => ActivityAction::Returned,
            RequestStatus::Assigned => ActivityAction::Assigned,
            RequestStatus::InProgress => ActivityAction::Started,
            RequestStatus::Completed => ActivityAction::Completed,
            RequestStatus::Draft => throw new LogicException('No transition leads back to draft.'),
        };
    }
}
