<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class DatabaseSeeder extends Seeder
{
    private RequestWorkflow $workflow;

    private Carbon $clock;

    public function run(): void
    {
        $this->workflow = app(RequestWorkflow::class);
        $this->clock = now()->subDays(10);

        $manager = User::factory()->manager()->create([
            'name' => 'Morgan Manager',
            'email' => 'manager@example.com',
        ]);
        $alice = User::factory()->employee()->reportingTo($manager)->create([
            'name' => 'Alice Employee',
            'email' => 'alice@example.com',
        ]);
        $bob = User::factory()->employee()->reportingTo($manager)->create([
            'name' => 'Bob Employee',
            'email' => 'bob@example.com',
        ]);
        $ops = User::factory()->operations()->create([
            'name' => 'Olivia Operations',
            'email' => 'ops@example.com',
        ]);
        $ops2 = User::factory()->operations()->create([
            'name' => 'Omar Operations',
            'email' => 'ops2@example.com',
        ]);

        // draft
        $this->draft($alice, 'Replace faulty keyboard', 'Several keys stopped responding.', RequestCategory::Equipment, RequestPriority::Low);

        // submitted
        $request = $this->draft($bob, 'VPN access for remote work', 'I need VPN access to work from home on Fridays.', RequestCategory::Access, RequestPriority::Medium);
        $this->move($request, RequestStatus::Submitted, $bob);

        $request = $this->draft($alice, 'Install design software', 'Please install the team design tool on my laptop.', RequestCategory::ItSupport, RequestPriority::Low);
        $this->move($request, RequestStatus::Submitted, $alice);

        // returned
        $request = $this->draft($bob, 'New standing desk', 'A standing desk would help with my back pain.', RequestCategory::Equipment, RequestPriority::Medium);
        $request = $this->move($request, RequestStatus::Submitted, $bob);
        $this->move($request, RequestStatus::Returned, $manager, 'Please attach a cost estimate.');

        // rejected
        $request = $this->draft($alice, 'Premium monitor arm set', 'Requesting three premium monitor arms.', RequestCategory::Equipment, RequestPriority::Low);
        $request = $this->move($request, RequestStatus::Submitted, $alice);
        $this->move($request, RequestStatus::Rejected, $manager, 'Outside this quarter\'s equipment budget.');

        // approved
        $request = $this->draft($bob, 'Office air conditioning repair', 'The air conditioning in room 4 is not cooling.', RequestCategory::Maintenance, RequestPriority::High);
        $request = $this->move($request, RequestStatus::Submitted, $bob);
        $this->move($request, RequestStatus::Approved, $manager);

        // assigned
        $request = $this->draft($alice, 'Meeting room projector issue', 'The projector in the main meeting room flickers.', RequestCategory::ItSupport, RequestPriority::High);
        $request = $this->move($request, RequestStatus::Submitted, $alice);
        $request = $this->move($request, RequestStatus::Approved, $manager);
        $this->move($request, RequestStatus::Assigned, $ops, assignee: $ops);

        // in progress
        $request = $this->draft($bob, 'Email sync problems', 'My mailbox has not synced since Monday.', RequestCategory::ItSupport, RequestPriority::Urgent);
        $request = $this->move($request, RequestStatus::Submitted, $bob);
        $request = $this->move($request, RequestStatus::Approved, $manager);
        $request = $this->move($request, RequestStatus::Assigned, $ops2, assignee: $ops2);
        $this->move($request, RequestStatus::InProgress, $ops2);

        // completed
        $request = $this->draft($alice, 'Badge access for new floor', 'I need badge access to the third floor.', RequestCategory::Access, RequestPriority::Medium);
        $request = $this->move($request, RequestStatus::Submitted, $alice);
        $request = $this->move($request, RequestStatus::Approved, $manager);
        $request = $this->move($request, RequestStatus::Assigned, $ops, assignee: $ops);
        $request = $this->move($request, RequestStatus::InProgress, $ops);
        $this->move($request, RequestStatus::Completed, $ops, 'Badge activated.');

        // completed, with a full history: returned, edited, resubmitted
        $request = $this->draft($bob, 'Printer toner refill', 'The second-floor printer is out of toner.', RequestCategory::Maintenance, RequestPriority::Low);
        $request = $this->move($request, RequestStatus::Submitted, $bob);
        $request = $this->move($request, RequestStatus::Returned, $manager, 'Which printer exactly?');
        $this->tick();
        $request = $this->workflow->update($request, ['description' => 'Second-floor printer, model X200, is out of toner.'], $bob);
        $request = $this->move($request, RequestStatus::Submitted, $bob);
        $request = $this->move($request, RequestStatus::Approved, $manager, 'Thanks, approved.');
        $request = $this->move($request, RequestStatus::Assigned, $ops2, assignee: $ops2);
        $request = $this->move($request, RequestStatus::InProgress, $ops2);
        $this->move($request, RequestStatus::Completed, $ops2, 'Toner replaced.');

        Carbon::setTestNow();
    }

    private function draft(User $employee, string $title, string $description, RequestCategory $category, RequestPriority $priority): ServiceRequest
    {
        $this->tick();

        return $this->workflow->create($employee, [
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'priority' => $priority,
        ]);
    }

    private function move(ServiceRequest $request, RequestStatus $to, User $actor, ?string $comment = null, ?User $assignee = null): ServiceRequest
    {
        $this->tick();

        return $this->workflow->transition($request, $to, $actor, $comment, $assignee);
    }

    /** Move the clock forward so the seeded history has realistic, ordered timestamps. */
    private function tick(): void
    {
        $this->clock = $this->clock->copy()->addHours(3);

        Carbon::setTestNow($this->clock);
    }
}
