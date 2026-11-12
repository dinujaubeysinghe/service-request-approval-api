# Architecture notes

## Overview

A request moves through a fixed lifecycle. Three layers each answer one question, and none of them does another's job:

| Layer | Question | Failure |
|---|---|---|
| Policy (`ServiceRequestPolicy`) | Is this person allowed to try? | 403 |
| Workflow (`RequestWorkflow`) | Is this move legal from the current status? | 422 |
| Form Requests | Is the input valid? | 422 |

Controllers are thin: authorize, validate, call the service, return a resource.

## Key decisions

**One workflow service, one transition map.** The allowed moves live in `RequestStatus::allowedTransitions()`. Every status change goes through `RequestWorkflow::transition()`, so no controller contains status logic. A `transition()` call runs in one database transaction: it locks the row, re-checks the status, updates it, writes the audit row and dispatches the notification job. If any step fails, nothing is saved.

**Status is a string cast to a PHP enum, not a database ENUM.** It works the same on SQLite, MySQL and PostgreSQL, and adding a status needs no table change. The enum is the single source of truth, and a test checks all 64 status pairs against an independently written rule table.

**The approving manager is snapshotted.** `service_requests.manager_id` is copied from the requester when they submit, and again on every resubmit. A reorganisation during review therefore cannot change who approves an in-flight request. It also makes the manager's list a simple indexed query. A manager can never decide on their own request.

**The audit log is append-only.** `request_activities` has no `updated_at`, and the model throws on update or delete. Foreign keys to users use `restrictOnDelete`, so history can never lose its actors. Comments live in their own table, because discussion and state changes have different meanings.

**Concurrency.** `lockForUpdate()` followed by a status re-check stops two people acting on one request at the same time. Notification jobs use `afterCommit()`, so a worker never runs before the change is saved, and a rolled-back change sends nothing. SQLite ignores row locks, so the locking only does real work on MySQL or PostgreSQL. Tests prove the rollback behaviour but not true concurrency.

**Visibility is enforced in SQL.** `ServiceRequest::visibleTo()` scopes the list query, so pagination counts are correct. Operations' visible statuses come from one shared method (`RequestStatus::visibleToOperations()`), used by both the policy and the query. A test checks that the list and the policy never disagree.

**Consistent API behaviour.** One error envelope (`message`, `code`, `errors`) covers 401, 403, 404, 422 and 429. Sorting is whitelisted, page size is capped, and server-controlled fields (`status`, `requester_id`, `manager_id`) are never read from client input. Relations are eager-loaded, and a test asserts the query count does not grow with the number of rows.

**Three similar enums, three jobs.** `Decision` is the API's words (`return`), `RequestStatus` is the state (`returned`), and `ActivityAction` is what the log records.

**Seed data uses the real workflow.** The seeder drives requests through `RequestWorkflow`, so the demo data has genuine history and doubles as an end-to-end check.

## Trade-offs

- **Search** uses `LIKE '%term%'`, which cannot use an index. This is fine at this scale. Full-text search would be the next step.
- **Denormalised timestamps** (`submitted_at`, `completed_at` and so on) speed up reads and future SLA reporting. The activity log remains the source of truth.
- **No soft deletes.** The API never deletes requests, so history stays whole.
- **403 instead of 404** for a request you may not see. This is simpler and clearer for clients, but it confirms that the request exists.
- **Rules the schema cannot enforce** (an assignee must have the Operations role; a request in `assigned` or later must have an assignee) are enforced in the Form Request and the workflow service, and tested. Database CHECK constraints would add a second layer on MySQL or PostgreSQL, but SQLite cannot add them after table creation.
- **Notifications** use the database channel only. Email is a small addition through the same job.