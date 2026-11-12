<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ServiceRequest::class) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(RequestCategory::class)],
            'priority' => ['sometimes', Rule::enum(RequestPriority::class)],
            'submit' => ['sometimes', 'boolean'],
        ];
    }
}
