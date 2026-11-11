<?php

declare(strict_types=1);

use App\Models\ServiceRequest;
use App\Models\User;

function policyTeam(): array
{
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();

    return [$manager, $employee];
}

function policyRequest(User $employee, string $state = 'draft'): ServiceRequest
{
    return ServiceRequest::factory()->forRequester($employee)->{$state}()->create();
}

it('lets only employees create requests', function (string $role, bool $allowed) {
    $user = User::factory()->{$role}()->create();

    expect($user->can('create', ServiceRequest::class))->toBe($allowed);
})->with([
    'employee' => ['employee', true],
    'manager' => ['manager', false],
    'operations' => ['operations', false],
]);

it('lets the owner edit only draft and returned requests', function (string $state, bool $allowed) {
    [, $employee] = policyTeam();

    expect($employee->can('update', policyRequest($employee, $state)))->toBe($allowed);
})->with([
    'draft' => ['draft', true],
    'returned' => ['returned', true],
    'submitted' => ['submitted', false],
    'approved' => ['approved', false],
    'rejected' => ['rejected', false],
    'completed' => ['completed', false],
]);

it('forbids editing and submitting for everyone except the owner', function (string $action) {
    [$manager, $employee] = policyTeam();
    $otherEmployee = User::factory()->employee()->reportingTo($manager)->create();
    $ops = User::factory()->operations()->create();
    $request = policyRequest($employee);

    expect($otherEmployee->can($action, $request))->toBeFalse()
        ->and($manager->can($action, $request))->toBeFalse()
        ->and($ops->can($action, $request))->toBeFalse()
        ->and($employee->can($action, $request))->toBeTrue();
})->with(['update', 'submit']);

it('lets only the snapshotted manager decide', function () {
    [$manager, $employee] = policyTeam();
    $otherManager = User::factory()->manager()->create();
    $ops = User::factory()->operations()->create();
    $request = policyRequest($employee, 'submitted');

    expect($manager->can('decide', $request))->toBeTrue()
        ->and($otherManager->can('decide', $request))->toBeFalse()
        ->and($employee->can('decide', $request))->toBeFalse()
        ->and($ops->can('decide', $request))->toBeFalse();
});

it('keeps the approver after the requester changes manager', function () {
    [$manager, $employee] = policyTeam();
    $newManager = User::factory()->manager()->create();
    $request = policyRequest($employee, 'submitted');

    $employee->update(['manager_id' => $newManager->id]);

    expect($manager->can('decide', $request))->toBeTrue()
        ->and($newManager->can('decide', $request))->toBeFalse();
});

it('stops a manager deciding on their own request', function () {
    $manager = User::factory()->manager()->create();
    $request = ServiceRequest::factory()->submitted()->create([
        'requester_id' => $manager->id,
        'manager_id' => $manager->id,
    ]);

    expect($manager->can('decide', $request))->toBeFalse();
});

it('lets only Operations assign, start and complete', function (string $action) {
    [$manager, $employee] = policyTeam();
    $ops = User::factory()->operations()->create();
    $request = policyRequest($employee, 'approved');

    expect($ops->can($action, $request))->toBeTrue()
        ->and($employee->can($action, $request))->toBeFalse()
        ->and($manager->can($action, $request))->toBeFalse();
})->with(['assign', 'changeStatus']);

it('scopes viewing by role', function () {
    [$manager, $employee] = policyTeam();
    $otherEmployee = User::factory()->employee()->create();
    $otherManager = User::factory()->manager()->create();
    $request = policyRequest($employee, 'submitted');

    expect($employee->can('view', $request))->toBeTrue()
        ->and($manager->can('view', $request))->toBeTrue()
        ->and($otherEmployee->can('view', $request))->toBeFalse()
        ->and($otherManager->can('view', $request))->toBeFalse();
});

it('shows Operations only approved and later requests', function (string $state, bool $allowed) {
    [, $employee] = policyTeam();
    $ops = User::factory()->operations()->create();

    expect($ops->can('view', policyRequest($employee, $state)))->toBe($allowed);
})->with([
    'draft' => ['draft', false],
    'submitted' => ['submitted', false],
    'returned' => ['returned', false],
    'rejected' => ['rejected', false],
    'approved' => ['approved', true],
    'assigned' => ['assigned', true],
    'inProgress' => ['inProgress', true],
    'completed' => ['completed', true],
]);

it('lets only people who can view a request comment on it', function () {
    [$manager, $employee] = policyTeam();
    $outsider = User::factory()->employee()->create();
    $request = policyRequest($employee, 'submitted');

    expect($employee->can('comment', $request))->toBeTrue()
        ->and($manager->can('comment', $request))->toBeTrue()
        ->and($outsider->can('comment', $request))->toBeFalse();
});
