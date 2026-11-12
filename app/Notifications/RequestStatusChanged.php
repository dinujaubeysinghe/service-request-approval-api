<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class RequestStatusChanged extends Notification
{
    public function __construct(
        private readonly ServiceRequest $request,
        private readonly RequestStatus $status,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Service request {$this->status->value}: {$this->request->title}")
            ->line($this->message())
            ->line("Current status: {$this->status->value}");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'service_request_id' => $this->request->id,
            'title' => $this->request->title,
            'status' => $this->status->value,
            'message' => $this->message(),
        ];
    }

    private function message(): string
    {
        $title = $this->request->title;

        return match ($this->status) {
            RequestStatus::Submitted => "A request is waiting for your review: {$title}",
            RequestStatus::Approved => "Your request was approved: {$title}",
            RequestStatus::Rejected => "Your request was rejected: {$title}",
            RequestStatus::Returned => "Your request was returned for changes: {$title}",
            RequestStatus::Assigned => "A request was assigned: {$title}",
            RequestStatus::InProgress => "Work has started on your request: {$title}",
            RequestStatus::Completed => "Your request was completed: {$title}",
            RequestStatus::Draft => "Request updated: {$title}",
        };
    }
}
