<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\RequestActivity;
use App\Models\RequestComment;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** @return array<string, string> */
function requestPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Replacement laptop',
        'description' => 'My laptop battery no longer holds a charge.',
        'category' => 'equipment',
        'priority' => 'high',
    ], $overrides);
}

/** @return array{User, User} */
function employeeWithManager(): array
{
    $manager = User::factory()->manager()->create();
    $employee = User::factory()->employee()->reportingTo($manager)->create();

    return [$manager, $employee];
}

/** @return list<int> */
function listedIds(User $user): array
{
    Sanctum::actingAs($user);

    return collect(test()->getJson('/api/requests?per_page=100')->assertOk()->json('data'))
        ->pluck('id')->sort()->values()->all();
}

// ---------------------------------------------------------------- create

it('creates a draft owned by the authenticated employee and ignores forged fields', function () {
    [, $employee] = employeeWithManager();
    Sanctum::actingAs($employee);

    $this->postJson('/api/requests', requestPayload([
        'status' => 'approved',
        'requester_id' => 999,
        'manager_id' => 999,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.requester.id', $employee->id)
        ->assertJsonPath('data.manager_id', null);

    $activity = RequestActivity::query()->sole();

    expect($activity->action)->toBe(ActivityAction::Created)
        ->and($activity->actor_id)->toBe($employee->id)
        ->and($activity->from_status)->toBeNull()
        ->and($activity->to_status)->toBe(RequestStatus::Draft);
});

it('defaults the priority to medium when none is sent', function () {
    [, $employee] = employeeWithManager();
    Sanctum::actingAs($employee);

    $payload = requestPayload();
    unset($payload['priority']);

    $this->postJson('/api/requests', $payload)
        ->assertCreated()
        ->assertJsonPath('data.priority', 'medium');
});

it('creates and submits in one call', function () {
    [$manager, $employee] = employeeWithManager();
    Sanctum::actingAs($employee);

    $this->postJson('/api/requests', requestPayload(['submit' => true]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.manager_id', $manager->id);

    $actions = RequestActivity::query()->orderBy('id')->get()
        ->map(fn (RequestActivity $a) => $a->action->value)->all();

    expect($actions)->toBe(['created', 'submitted']);
});

it('saves nothing when create-and-submit fails because there is no manager', function () {
    $employee = User::factory()->employee()->create();
    Sanctum::actingAs($employee);

    $this->postJson('/api/requests', requestPayload(['submit' => true]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition');

    expect(ServiceRequest::count())->toBe(0)
        ->and(RequestActivity::count())->toBe(0);
});

it('forbids non-employees from creating requests', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->postJson('/api/requests', requestPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
})->with(['manager', 'operations']);

it('validates the create payload', function () {
    [, $employee] = employeeWithManager();
    Sanctum::actingAs($employee);

    $this->postJson('/api/requests', ['category' => 'banana', 'priority' => 'whenever'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['title', 'description', 'category', 'priority']);
});

// ---------------------------------------------------------------- update

it('lets the owner edit a draft or returned request and logs the changed fields', function (string $state) {
    [, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->forRequester($employee)->{$state}()->create();
    Sanctum::actingAs($employee);

    $this->putJson("/api/requests/{$request->id}", ['title' => 'A brand new title'])
        ->assertOk()
        ->assertJsonPath('data.title', 'A brand new title');

    $activity = RequestActivity::query()->sole();

    expect($activity->action)->toBe(ActivityAction::Updated)
        ->and($activity->metadata)->toBe(['changed' => ['title']])
        ->and($request->fresh()->status->value)->toBe($state);
})->with(['draft', 'returned']);

it('forbids editing a request that is no longer editable', function (string $state) {
    [, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->forRequester($employee)->{$state}()->create();
    Sanctum::actingAs($employee);

    $this->putJson("/api/requests/{$request->id}", ['title' => 'Too late'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
})->with(['submitted', 'approved', 'rejected', 'completed']);

it("forbids editing someone else's request", function () {
    [$manager, $employee] = employeeWithManager();
    $colleague = User::factory()->employee()->reportingTo($manager)->create();
    $request = ServiceRequest::factory()->forRequester($employee)->create();

    Sanctum::actingAs($colleague);

    $this->putJson("/api/requests/{$request->id}", ['title' => 'Mine now'])->assertForbidden();
});

it('rejects an edit at the service level if the request stopped being editable', function () {
    [, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create();

    expect(fn () => (new RequestWorkflow)->update($request, ['title' => 'Late'], $employee))
        ->toThrow(InvalidTransitionException::class, 'cannot be edited');
});

// ---------------------------------------------------------------- submit

it('submits a draft and snapshots the manager', function () {
    [$manager, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);
    Sanctum::actingAs($employee);

    $this->postJson("/api/requests/{$request->id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.manager_id', $manager->id);
});

it('returns 422 when submitting a request that was already submitted', function () {
    [, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create();
    Sanctum::actingAs($employee);

    $this->postJson("/api/requests/{$request->id}/submit")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_transition');
});

it("forbids submitting someone else's request", function () {
    [$manager, $employee] = employeeWithManager();
    $colleague = User::factory()->employee()->reportingTo($manager)->create();
    $request = ServiceRequest::factory()->forRequester($employee)->create();

    Sanctum::actingAs($colleague);

    $this->postJson("/api/requests/{$request->id}/submit")->assertForbidden();
});

// ---------------------------------------------------------------- show

it('shows a request with its comments and activity history', function () {
    [$manager, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create();
    RequestComment::factory()->create([
        'service_request_id' => $request->id,
        'user_id' => $employee->id,
        'body' => 'Please hurry.',
    ]);
    RequestActivity::create([
        'service_request_id' => $request->id,
        'actor_id' => $employee->id,
        'action' => ActivityAction::Submitted,
        'from_status' => RequestStatus::Draft,
        'to_status' => RequestStatus::Submitted,
    ]);

    foreach ([$employee, $manager] as $viewer) {
        Sanctum::actingAs($viewer);

        $this->getJson("/api/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.comments.0.body', 'Please hurry.')
            ->assertJsonPath('data.comments.0.author.id', $employee->id)
            ->assertJsonPath('data.activities.0.action', 'submitted')
            ->assertJsonPath('data.activities.0.actor.id', $employee->id);
    }
});

it('forbids viewing a request you are not involved in', function () {
    [, $employee] = employeeWithManager();
    $request = ServiceRequest::factory()->forRequester($employee)->submitted()->create();

    Sanctum::actingAs(User::factory()->employee()->create());

    $this->getJson("/api/requests/{$request->id}")->assertForbidden();
});

it('returns 404 for a request that does not exist', function () {
    Sanctum::actingAs(User::factory()->employee()->create());

    $this->getJson('/api/requests/99999')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('returns 401 for every request endpoint without a token', function () {
    $this->getJson('/api/requests')->assertUnauthorized();
    $this->postJson('/api/requests', requestPayload())->assertUnauthorized();
    $this->getJson('/api/requests/1')->assertUnauthorized();
    $this->putJson('/api/requests/1', [])->assertUnauthorized();
    $this->postJson('/api/requests/1/submit')->assertUnauthorized();
});

// ---------------------------------------------------------------- list

it('scopes the list by role and matches the policy exactly', function () {
    [$manager, $employee] = employeeWithManager();
    $other = User::factory()->employee()->create();
    $ops = User::factory()->operations()->create();

    $draft = ServiceRequest::factory()->create(['requester_id' => $employee->id]);
    $submitted = ServiceRequest::factory()->forRequester($employee)->submitted()->create();
    $approved = ServiceRequest::factory()->forRequester($employee)->approved()->create();
    $foreign = ServiceRequest::factory()->forRequester($other)->approved()->create();

    expect(listedIds($employee))->toEqualCanonicalizing([$draft->id, $submitted->id, $approved->id])
        ->and(listedIds($manager))->toEqualCanonicalizing([$submitted->id, $approved->id])
        ->and(listedIds($ops))->toEqualCanonicalizing([$approved->id, $foreign->id]);

    // The list and the policy must never disagree.
    foreach ([$employee, $manager, $ops, $other] as $user) {
        $expected = ServiceRequest::all()
            ->filter(fn (ServiceRequest $r) => $user->can('view', $r))
            ->pluck('id')->sort()->values()->all();

        expect(listedIds($user))->toEqualCanonicalizing($expected);
    }
});

it('filters by status and searches title and description', function () {
    $employee = User::factory()->employee()->create();
    ServiceRequest::factory()->forRequester($employee)->create([
        'title' => 'Broken keyboard',
        'description' => 'Keys are sticking.',
    ]);
    ServiceRequest::factory()->forRequester($employee)->submitted()->create([
        'title' => 'VPN access',
        'description' => 'Needed for remote work.',
    ]);
    Sanctum::actingAs($employee);

    $this->getJson('/api/requests?status=submitted')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'VPN access');

    $this->getJson('/api/requests?search=keyboard')->assertJsonCount(1, 'data');
    $this->getJson('/api/requests?search=remote')->assertJsonCount(1, 'data');
    $this->getJson('/api/requests?search=nothingmatches')->assertJsonCount(0, 'data');
});

it('sorts by a whitelisted column in either direction', function () {
    $employee = User::factory()->employee()->create();
    foreach (['Bravo', 'Alpha', 'Charlie'] as $title) {
        ServiceRequest::factory()->forRequester($employee)->create(['title' => $title]);
    }
    Sanctum::actingAs($employee);

    expect($this->getJson('/api/requests?sort=title')->json('data.*.title'))->toBe(['Alpha', 'Bravo', 'Charlie'])
        ->and($this->getJson('/api/requests?sort=-title')->json('data.*.title'))->toBe(['Charlie', 'Bravo', 'Alpha']);
});

it('rejects sorting by a column that is not whitelisted', function () {
    Sanctum::actingAs(User::factory()->employee()->create());

    $this->getJson('/api/requests?sort=password')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed');
});

it('paginates and caps the page size', function () {
    $employee = User::factory()->employee()->create();
    ServiceRequest::factory()->count(20)->forRequester($employee)->create();
    Sanctum::actingAs($employee);

    $this->getJson('/api/requests?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.last_page', 4);

    $this->getJson('/api/requests?per_page=101')->assertUnprocessable();
});

it('uses the same number of queries however many requests are listed', function () {
    $employee = User::factory()->employee()->create();
    $ops = User::factory()->operations()->create();
    $countQueries = function () use ($employee): int {
        Sanctum::actingAs($employee);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/requests?per_page=100')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    ServiceRequest::factory()->count(3)->forRequester($employee)->assigned()->create(['assignee_id' => $ops->id]);
    $few = $countQueries();

    ServiceRequest::factory()->count(12)->forRequester($employee)->assigned()->create(['assignee_id' => $ops->id]);
    $many = $countQueries();

    expect($many)->toBe($few);
});
