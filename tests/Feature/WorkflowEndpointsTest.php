<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** @return array{User, User, User} manager, employee, operations */
function flowTeam(): array
{
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();
    $ops = User::factory()->operations()->create();

    return [$manager, $employee, $ops];
}

function flowRequest(User $employee, string $state): ServiceRequest
{
    return ServiceRequest::factory()->forRequester($employee)->{$state}()->create();
}

// ------------------------------------------------------- happy path

it('takes a request through the whole lifecycle over HTTP, one role at a time', function () {
    [$manager, $employee, $ops] = flowTeam();

    Sanctum::actingAs($employee);
    $id = $this->postJson('/api/requests', [
        'title' => 'New monitor',
        'description' => 'My current monitor flickers.',
        'category' => 'equipment',
        'submit' => true,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($manager);
    $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approve'])
        ->assertOk()->assertJsonPath('data.status', 'approved');

    Sanctum::actingAs($ops);
    $this->postJson("/api/requests/{$id}/assign", ['assignee_id' => $ops->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.assignee.id', $ops->id);
    $this->postJson("/api/requests/{$id}/status", ['status' => 'in_progress'])
        ->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/requests/{$id}/status", ['status' => 'completed'])
        ->assertOk()->assertJsonPath('data.status', 'completed');

    Sanctum::actingAs($employee);
    $actions = $this->getJson("/api/requests/{$id}")->assertOk()->json('data.activities.*.action');

    expect($actions)->toBe(['created', 'submitted', 'approved', 'assigned', 'started', 'completed']);
});

it('lets an employee fix and resubmit a returned request', function () {
    [$manager, $employee] = flowTeam();
    $request = flowRequest($employee, 'submitted');

    Sanctum::actingAs($manager);
    $this->postJson("/api/requests/{$request->id}/decision", [
        'decision' => 'return',
        'comment' => 'Please add a cost estimate.',
    ])->assertOk()->assertJsonPath('data.status', 'returned');

    $activity = RequestActivity::query()->sole();
    expect($activity->action)->toBe(ActivityAction::Returned)
        ->and($activity->actor_id)->toBe($manager->id)
        ->and($activity->comment)->toBe('Please add a cost estimate.');

    Sanctum::actingAs($employee);
    $this->putJson("/api/requests/{$request->id}", ['description' => 'Estimate: 300 USD.'])->assertOk();
    $this->postJson("/api/requests/{$request->id}/submit")
        ->assertOk()->assertJsonPath('data.status', 'submitted');
});

// ------------------------------------------------------- decision

it('records approve and reject decisions', function (string $decision, string $status) {
    [$manager, $employee] = flowTeam();
    $request = flowRequest($employee, 'submitted');
    Sanctum::actingAs($manager);

    $this->postJson("/api/requests/{$request->id}/decision", ['decision' => $decision, 'comment' => 'Reason.'])
        ->assertOk()
        ->assertJsonPath('data.status', $status);
})->with([
    'approve' => ['approve', 'approved'],
    'reject' => ['reject', 'rejected'],
]);

it('requires a comment to reject or return but not to approve', function (string $decision, int $status) {
    [$manager, $employee] = flowTeam();
    $request = flowRequest($employee, 'submitted');
    Sanctum::actingAs($manager);

    $response = $this->postJson("/api/requests/{$request->id}/decision", ['decision' => $decision]);

    $response->assertStatus($status);

    if ($status === 422) {
        $response->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['comment']);
    }
})->with([
    'reject' => ['reject', 422],
    'return' => ['return', 422],
    'approve' => ['approve', 200],
]);

it('validates the decision value', function () {
    [$manager, $employee] = flowTeam();
    $request = flowRequest($employee, 'submitted');
    Sanctum::actingAs($manager);

    $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['decision']);
});

it('forbids everyone except the approving manager from deciding', function (string $who) {
    [$manager, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'submitted');

    $actor = match ($who) {
        'other manager' => User::factory()->manager()->create(),
        'requester' => $employee,
        'operations' => $ops,
    };
    Sanctum::actingAs($actor);

    $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect($request->fresh()->status)->toBe(RequestStatus::Submitted);
})->with(['other manager', 'requester', 'operations']);

it('stops a manager from approving their own request', function () {
    $manager = User::factory()->manager()->create();
    $request = ServiceRequest::factory()->submitted()->create([
        'requester_id' => $manager->id,
        'manager_id' => $manager->id,
    ]);
    Sanctum::actingAs($manager);

    $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertForbidden();
});

it('returns 422 when the decision is not possible from the current status', function (string $state) {
    [$manager, $employee] = flowTeam();
    $request = flowRequest($employee, $state);
    Sanctum::actingAs($manager);

    $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition');
})->with(['draft', 'approved', 'rejected', 'completed']);

// ------------------------------------------------------- assign

it('assigns an Operations user and records who was chosen', function () {
    [, $employee, $ops] = flowTeam();
    $other = User::factory()->operations()->create();
    $request = flowRequest($employee, 'approved');
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $other->id])
        ->assertOk()
        ->assertJsonPath('data.assignee.id', $other->id);

    $activity = RequestActivity::query()->sole();
    expect($activity->actor_id)->toBe($ops->id)
        ->and($activity->metadata)->toBe(['assignee_id' => $other->id]);
});

it('rejects an assignee who is not an Operations user', function () {
    [$manager, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'approved');
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $manager->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['assignee_id']);

    $this->postJson("/api/requests/{$request->id}/assign", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignee_id']);
});

it('forbids non-Operations users from assigning and changing status', function (string $role, string $endpoint, array $payload) {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'approved');
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->postJson("/api/requests/{$request->id}/{$endpoint}", $payload + ['assignee_id' => $ops->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
})->with([
    'employee assign' => ['employee', 'assign', []],
    'manager assign' => ['manager', 'assign', []],
    'employee status' => ['employee', 'status', ['status' => 'in_progress']],
    'manager status' => ['manager', 'status', ['status' => 'in_progress']],
]);

it('returns 422 when assigning a request that is not approved', function (string $state) {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, $state);
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $ops->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition');
})->with(['draft', 'submitted', 'rejected', 'assigned', 'completed']);

// ------------------------------------------------------- start / complete

it('lets Operations start and then complete an assigned request', function () {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'assigned');
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/status", ['status' => 'in_progress'])
        ->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/requests/{$request->id}/status", ['status' => 'completed', 'comment' => 'Replaced.'])
        ->assertOk()->assertJsonPath('data.status', 'completed');

    expect($request->fresh()->completed_at)->not->toBeNull();
});

it('returns 422 for impossible status changes', function (string $state, string $target) {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, $state);
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/status", ['status' => $target])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition');
})->with([
    'complete a draft' => ['draft', 'completed'],
    'complete without starting' => ['assigned', 'completed'],
    'start an unassigned request' => ['approved', 'in_progress'],
    'change a rejected request' => ['rejected', 'in_progress'],
    'change a completed request' => ['completed', 'in_progress'],
]);

it('only accepts in_progress or completed on the status endpoint', function () {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'assigned');
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/status", ['status' => 'approved'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

// ------------------------------------------------------- comments

it('lets participants comment and logs it in the activity history', function () {
    [$manager, $employee] = flowTeam();
    $request = flowRequest($employee, 'submitted');

    foreach ([$employee, $manager] as $author) {
        Sanctum::actingAs($author);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'A question.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'A question.')
            ->assertJsonPath('data.author.id', $author->id);
    }

    expect($request->comments()->count())->toBe(2)
        ->and(RequestActivity::query()->where('action', ActivityAction::Commented->value)->count())->toBe(2);
});

it('forbids commenting for people who cannot see the request', function () {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'submitted');

    foreach ([User::factory()->employee()->create(), User::factory()->manager()->create(), $ops] as $outsider) {
        Sanctum::actingAs($outsider);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Hello'])
            ->assertForbidden();
    }

    expect($request->comments()->count())->toBe(0);
});

it('lets Operations comment once the request is approved', function () {
    [, $employee, $ops] = flowTeam();
    $request = flowRequest($employee, 'approved');
    Sanctum::actingAs($ops);

    $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Ordering parts.'])->assertCreated();
});

it('validates the comment body', function () {
    [, $employee] = flowTeam();
    $request = flowRequest($employee, 'submitted');
    Sanctum::actingAs($employee);

    $this->postJson("/api/requests/{$request->id}/comments", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body']);
});

// ------------------------------------------------------- unauthenticated / missing

it('returns 401 without a token', function (string $endpoint) {
    $this->postJson("/api/requests/1/{$endpoint}")->assertUnauthorized();
})->with(['decision', 'assign', 'status', 'comments']);

it('returns 404 for a request that does not exist', function (string $endpoint) {
    Sanctum::actingAs(User::factory()->manager()->create());

    $this->postJson("/api/requests/99999/{$endpoint}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
})->with(['decision', 'assign', 'status', 'comments']);
