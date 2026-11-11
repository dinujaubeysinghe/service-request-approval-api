<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;

it('submits a draft, snapshots the manager and logs the activity', function () {
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();
    $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);

    $updated = (new RequestWorkflow)->transition($request, RequestStatus::Submitted, $employee);

    expect($updated->status)->toBe(RequestStatus::Submitted)
        ->and($updated->manager_id)->toBe($manager->id)
        ->and($updated->submitted_at)->not->toBeNull();

    $activity = RequestActivity::query()->sole();

    expect($activity->actor_id)->toBe($employee->id)
        ->and($activity->action)->toBe(ActivityAction::Submitted)
        ->and($activity->from_status)->toBe(RequestStatus::Draft)
        ->and($activity->to_status)->toBe(RequestStatus::Submitted);
});

it('rejects an illegal transition and changes nothing', function () {
    $employee = User::factory()->employee()->create();
    $request = ServiceRequest::factory()->forRequester($employee)->create();

    expect(fn () => (new RequestWorkflow)->transition($request, RequestStatus::Completed, $employee))
        ->toThrow(InvalidTransitionException::class, "Cannot move a request from 'draft' to 'completed'.");

    expect($request->fresh()->status)->toBe(RequestStatus::Draft)
        ->and(RequestActivity::count())->toBe(0);
});

it('never changes a final request', function (string $state) {
    $employee = User::factory()->employee()->create();
    $request = ServiceRequest::factory()->forRequester($employee)->{$state}()->create();

    expect(fn () => (new RequestWorkflow)->transition($request, RequestStatus::Submitted, $employee))
        ->toThrow(InvalidTransitionException::class);
})->with(['rejected', 'completed']);

it('refuses to submit when the requester has no manager', function () {
    $employee = User::factory()->employee()->create();
    $request = ServiceRequest::factory()->forRequester($employee)->create();

    expect(fn () => (new RequestWorkflow)->transition($request, RequestStatus::Submitted, $employee))
        ->toThrow(InvalidTransitionException::class, 'no manager');

    expect($request->fresh()->status)->toBe(RequestStatus::Draft);
});

it('stores the decision comment on the activity row', function () {
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create();

    (new RequestWorkflow)->transition($request, RequestStatus::Returned, $manager, 'Please add a cost estimate.');

    $activity = RequestActivity::query()->sole();

    expect($activity->action)->toBe(ActivityAction::Returned)
        ->and($activity->comment)->toBe('Please add a cost estimate.')
        ->and($request->fresh()->decided_at)->not->toBeNull();
});

it('re-snapshots the manager when a returned request is resubmitted', function () {
    $oldManager = User::factory()->manager()->create();
    $newManager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($oldManager)->create();
    $request = ServiceRequest::factory()->forRequester($employee)->returned()->create();

    $employee->update(['manager_id' => $newManager->id]);

    $updated = (new RequestWorkflow)->transition($request, RequestStatus::Submitted, $employee);

    expect($updated->manager_id)->toBe($newManager->id);
});

it('assigns only to an Operations user and records the assignee', function () {
    $employee = User::factory()->employee()->create();
    $ops = User::factory()->operations()->create();
    $request = ServiceRequest::factory()->forRequester($employee)->approved()->create();

    $updated = (new RequestWorkflow)->transition($request, RequestStatus::Assigned, $ops, assignee: $ops);

    expect($updated->assignee_id)->toBe($ops->id)
        ->and($updated->assigned_at)->not->toBeNull()
        ->and(RequestActivity::query()->sole()->metadata)->toBe(['assignee_id' => $ops->id]);
});

it('refuses to assign to a non-Operations user', function () {
    $employee = User::factory()->employee()->create();
    $ops = User::factory()->operations()->create();
    $request = ServiceRequest::factory()->forRequester($employee)->approved()->create();

    expect(fn () => (new RequestWorkflow)->transition($request, RequestStatus::Assigned, $ops, assignee: $employee))
        ->toThrow(InvalidArgumentException::class);
});

it('walks a request through the whole lifecycle', function () {
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();
    $ops = User::factory()->operations()->create();
    $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);
    $workflow = new RequestWorkflow;

    $workflow->transition($request, RequestStatus::Submitted, $employee);
    $workflow->transition($request, RequestStatus::Approved, $manager, 'Approved.');
    $workflow->transition($request, RequestStatus::Assigned, $ops, assignee: $ops);
    $workflow->transition($request, RequestStatus::InProgress, $ops);
    $workflow->transition($request, RequestStatus::Completed, $ops);

    $request->refresh();

    expect($request->status)->toBe(RequestStatus::Completed)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->activities->map(fn (RequestActivity $a) => $a->action->value)->all())
        ->toBe(['submitted', 'approved', 'assigned', 'started', 'completed']);
});
