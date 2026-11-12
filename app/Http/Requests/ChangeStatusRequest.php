<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('serviceRequest')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Operations may only start or complete work through this endpoint.
            'status' => ['required', Rule::in([RequestStatus::InProgress->value, RequestStatus::Completed->value])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function target(): RequestStatus
    {
        return RequestStatus::from($this->string('status')->toString());
    }

    public function commentText(): ?string
    {
        return $this->filled('comment') ? $this->string('comment')->toString() : null;
    }
}
