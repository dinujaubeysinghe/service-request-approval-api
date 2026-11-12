<?php

declare(strict_types=1);

use App\Enums\RequestStatus;
use App\Jobs\SendRequestStatusNotification;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use App\Services\RequestWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/** @return array{User, User, User} manager, employee, operations */
function notifyTeam(): array
{
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();
    $ops = User::factory()->operations()->create();

    return [$manager, $employee, $ops];
}

// ------------------------------------------------------- dispatching

it('dispatches the notification job when a request changes status', function () {
    Queue::fake();
    [, $employee] = notifyTeam();
    $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);

    (new RequestWorkflow)->transition($request, RequestStatus::Submitted, $employee);

    Queue::assertPushed(
        SendRequestStatusNotification::class,
        fn (SendRequestStatusNotification $job) => $job->serviceRequestId === $request->id
            && $job->status === RequestStatus::Submitted,
    );
    Queue::assertPushed(SendRequestStatusNotification::class, 1);
});

it('dispatches the job from the HTTP decision endpoint', function () {
    Queue::fake();
    [$manager, $employee] = notifyTeam();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create();
    Sanctum::actingAs($manager);

    $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertOk();

    Queue::assertPushed(
        SendRequestStatusNotification::class,
        fn (SendRequestStatusNotification $job) => $job->status === RequestStatus::Approved,
    );
});

it('dispatches nothing when the transition is illegal', function () {
    Queue::fake();
    [, $employee] = notifyTeam();
    $request = ServiceRequest::factory()->forRequester($employee)->create();

    expect(fn () => (new RequestWorkflow)->transition($request, RequestStatus::Completed, $employee))
        ->toThrow(Exception::class);

    Queue::assertNothingPushed();
});

it('dispatches nothing when create-and-submit is rolled back', function () {
    Queue::fake();
    $employee = User::factory()->employee()->create(); // no manager
    Sanctum::actingAs($employee);

    $this->postJson('/api/requests', [
        'title' => 'Laptop',
        'description' => 'Needs replacing.',
        'category' => 'equipment',
        'submit' => true,
    ])->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('does not dispatch for plain edits or when only a draft is created', function () {
    Queue::fake();
    [, $employee] = notifyTeam();
    Sanctum::actingAs($employee);

    $this->postJson('/api/requests', [
        'title' => 'Laptop',
        'description' => 'Needs replacing.',
        'category' => 'equipment',
    ])->assertCreated();

    Queue::assertNothingPushed();
});

// ------------------------------------------------------- delivery

it('notifies the right people for each status', function (string $state, RequestStatus $status, array $expected) {
    [$manager, $employee, $ops] = notifyTeam();
    $request = ServiceRequest::factory()->forRequester($employee)->{$state}()->create(['assignee_id' => $ops->id]);

    (new SendRequestStatusNotification($request->id, $status))->handle();

    $people = ['manager' => $manager, 'employee' => $employee, 'operations' => $ops];

    foreach ($people as $name => $user) {
        expect($user->notifications()->count())->toBe(in_array($name, $expected, true) ? 1 : 0);
    }
})->with([
    'submitted' => ['submitted', RequestStatus::Submitted, ['manager']],
    'approved' => ['approved', RequestStatus::Approved, ['employee']],
    'rejected' => ['rejected', RequestStatus::Rejected, ['employee']],
    'returned' => ['returned', RequestStatus::Returned, ['employee']],
    'assigned' => ['assigned', RequestStatus::Assigned, ['employee', 'operations']],
    'in progress' => ['inProgress', RequestStatus::InProgress, ['employee']],
    'completed' => ['completed', RequestStatus::Completed, ['employee']],
]);

it('stores a readable payload on the notification', function () {
    [$manager, $employee] = notifyTeam();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create(['title' => 'New chair']);

    (new SendRequestStatusNotification($request->id, RequestStatus::Submitted))->handle();

    $data = $manager->notifications()->sole()->data;

    expect($data['service_request_id'])->toBe($request->id)
        ->and($data['status'])->toBe('submitted')
        ->and($data['message'])->toContain('New chair');
});

it('delivers status notifications through both database and email', function () {
    [$manager, $employee] = notifyTeam();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create(['title' => 'New chair']);
    $notification = new RequestStatusChanged($request, RequestStatus::Submitted);

    expect($notification->via($manager))
        ->toContain('database')
        ->toContain('mail');

    expect($notification->toMail($manager)->subject)
        ->toBe('Service request submitted: New chair');
});

it('does nothing if the request no longer exists', function () {
    (new SendRequestStatusNotification(99999, RequestStatus::Submitted))->handle();

    expect(DB::table('notifications')->count())->toBe(0);
});

it('delivers notifications end to end when the queue runs synchronously', function () {
    [$manager, $employee] = notifyTeam();
    $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);

    (new RequestWorkflow)->transition($request, RequestStatus::Submitted, $employee);

    expect($manager->notifications()->count())->toBe(1);
});
