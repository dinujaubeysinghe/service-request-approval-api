<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(RequestCategory::cases()),
            'priority' => fake()->randomElement(RequestPriority::cases()),
            'status' => RequestStatus::Draft,
        ];
    }

    /** Create the request for a given employee and snapshot their manager. */
    public function forRequester(User $requester): static
    {
        return $this->state(fn () => [
            'requester_id' => $requester->id,
            'manager_id' => $requester->manager_id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => RequestStatus::Draft]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Submitted,
            'submitted_at' => now()->subDays(5),
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Returned,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Rejected,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Approved,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
        ]);
    }

    public function assigned(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Assigned,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
            'assigned_at' => now()->subDays(3),
            'assignee_id' => User::factory()->operations(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::InProgress,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
            'assigned_at' => now()->subDays(3),
            'started_at' => now()->subDays(2),
            'assignee_id' => User::factory()->operations(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Completed,
            'submitted_at' => now()->subDays(5),
            'decided_at' => now()->subDays(4),
            'assigned_at' => now()->subDays(3),
            'started_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
            'assignee_id' => User::factory()->operations(),
        ]);
    }
}
