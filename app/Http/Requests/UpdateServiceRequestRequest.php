<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('serviceRequest')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'category' => ['sometimes', 'required', Rule::enum(RequestCategory::class)],
            'priority' => ['sometimes', 'required', Rule::enum(RequestPriority::class)],
        ];
    }
}
