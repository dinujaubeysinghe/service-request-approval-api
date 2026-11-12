<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListServiceRequestsRequest extends FormRequest
{
    /** Only these columns may be sorted on. User input never reaches orderBy directly. */
    private const SORTABLE = ['created_at', 'updated_at', 'title', 'status'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sorts = array_merge(self::SORTABLE, array_map(fn (string $c) => "-{$c}", self::SORTABLE));

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(RequestStatus::class)],
            'sort' => ['nullable', Rule::in($sorts)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function sortColumn(): string
    {
        return ltrim($this->string('sort', '-created_at')->toString(), '-');
    }

    public function sortDirection(): string
    {
        return str_starts_with($this->string('sort', '-created_at')->toString(), '-') ? 'desc' : 'asc';
    }
}
