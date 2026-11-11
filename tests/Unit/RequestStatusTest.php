<?php

declare(strict_types=1);

use App\Enums\RequestStatus;

/*
 * The expected rules are written out independently here, NOT read from
 * allowedTransitions(). If the test reused the enum's own map, it could
 * never fail, even when the map is wrong.
 */
const EXPECTED_TRANSITIONS = [
    'draft' => ['submitted'],
    'submitted' => ['approved', 'rejected', 'returned'],
    'returned' => ['submitted'],
    'approved' => ['assigned'],
    'assigned' => ['in_progress'],
    'in_progress' => ['completed'],
    'rejected' => [],
    'completed' => [],
];

dataset('status_pairs', function () {
    foreach (RequestStatus::cases() as $from) {
        foreach (RequestStatus::cases() as $to) {
            yield "{$from->value} to {$to->value}" => [
                $from,
                $to,
                in_array($to->value, EXPECTED_TRANSITIONS[$from->value], true),
            ];
        }
    }
});

it('allows only valid transitions', function (RequestStatus $from, RequestStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with('status_pairs');

it('treats rejected and completed as final', function (RequestStatus $status) {
    expect($status->isFinal())->toBeTrue()
        ->and($status->allowedTransitions())->toBe([]);
})->with([
    'rejected' => RequestStatus::Rejected,
    'completed' => RequestStatus::Completed,
]);

it('treats every other status as not final', function (RequestStatus $status) {
    expect($status->isFinal())->toBeFalse();
})->with([
    'draft' => RequestStatus::Draft,
    'submitted' => RequestStatus::Submitted,
    'returned' => RequestStatus::Returned,
    'approved' => RequestStatus::Approved,
    'assigned' => RequestStatus::Assigned,
    'in_progress' => RequestStatus::InProgress,
]);

it('lets employees edit only draft and returned requests', function () {
    $editable = array_filter(
        RequestStatus::cases(),
        fn (RequestStatus $status) => $status->isEditable(),
    );

    expect(array_map(fn (RequestStatus $s) => $s->value, array_values($editable)))
        ->toBe(['draft', 'returned']);
});

it('defines a rule for every status', function () {
    expect(array_map(fn (RequestStatus $s) => $s->value, RequestStatus::cases()))
        ->toEqualCanonicalizing(array_keys(EXPECTED_TRANSITIONS));
});
