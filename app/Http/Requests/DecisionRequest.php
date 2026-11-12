<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Decision;
use App\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('decide', $this->route('serviceRequest')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(Decision::class)],
            // Rejecting or returning without a reason is not allowed.
            'comment' => ['required_if:decision,reject,return', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function target(): RequestStatus
    {
        return Decision::from($this->string('decision')->toString())->toStatus();
    }

    public function commentText(): ?string
    {
        return $this->filled('comment') ? $this->string('comment')->toString() : null;
    }
}
