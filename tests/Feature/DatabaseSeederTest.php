<?php

declare(strict_types=1);

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('seeds one user for each role and a manager with two direct reports', function () {
    foreach (Role::cases() as $role) {
        expect(User::query()->where('role', $role->value)->exists())->toBeTrue();
    }

    $manager = User::query()->where('email', 'manager@example.com')->firstOrFail();

    expect($manager->directReports()->count())->toBe(2);
});

it('seeds about ten requests covering every status', function () {
    $statuses = ServiceRequest::query()->pluck('status')->map(fn (RequestStatus $s) => $s->value)->unique()->sort()->values()->all();
    $expected = collect(RequestStatus::cases())->map(fn (RequestStatus $s) => $s->value)->sort()->values()->all();

    expect(ServiceRequest::count())->toBe(10)
        ->and($statuses)->toBe($expected);
});

it('seeds a consistent audit trail for every request', function () {
    ServiceRequest::query()->with('activities')->get()->each(function (ServiceRequest $request) {
        expect($request->activities)->not->toBeEmpty()
            ->and($request->activities->last()->to_status)->toBe($request->status);

        if (in_array($request->status, [RequestStatus::Assigned, RequestStatus::InProgress, RequestStatus::Completed], true)) {
            expect($request->assignee_id)->not->toBeNull();
        }
    });
});

it('lets every seeded user log in with the documented password', function () {
    foreach (User::all() as $user) {
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }
});
