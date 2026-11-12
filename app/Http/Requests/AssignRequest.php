<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assign', $this->route('serviceRequest')) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // The owner must exist and must be an Operations user.
            'assignee_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', Role::Operations->value),
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function assignee(): User
    {
        return User::query()->findOrFail($this->integer('assignee_id'));
    }

    public function commentText(): ?string
    {
        return $this->filled('comment') ? $this->string('comment')->toString() : null;
    }
}
