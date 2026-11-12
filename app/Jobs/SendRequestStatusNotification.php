<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

final class SendRequestStatusNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $serviceRequestId,
        public readonly RequestStatus $status,
    ) {}

    public function handle(): void
    {
        $request = ServiceRequest::query()
            ->with(['requester', 'manager', 'assignee'])
            ->find($this->serviceRequestId);

        if ($request === null) {
            return;
        }

        $recipients = $this->recipients($request);

        if ($recipients === []) {
            return;
        }

        Notification::send($recipients, new RequestStatusChanged($request, $this->status));
    }

    /** @return list<User> */
    private function recipients(ServiceRequest $request): array
    {
        $candidates = match ($this->status) {
            RequestStatus::Submitted => [$request->manager],
            RequestStatus::Assigned => [$request->assignee, $request->requester],
            RequestStatus::Approved,
            RequestStatus::Rejected,
            RequestStatus::Returned,
            RequestStatus::InProgress,
            RequestStatus::Completed => [$request->requester],
            RequestStatus::Draft => [],
        };

        $recipients = [];

        foreach ($candidates as $user) {
            if ($user instanceof User) {
                $recipients[] = $user;
            }
        }

        return $recipients;
    }
}
