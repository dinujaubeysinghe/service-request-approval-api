# Service Request & Approval API

[![CI](https://github.com/dinujaubeysinghe/service-request-approval-api/actions/workflows/ci.yml/badge.svg)](https://github.com/dinujaubeysinghe/service-request-approval-api/actions/workflows/ci.yml)

A Laravel REST API for internal service requests. Employees submit requests, managers approve, reject or return them, and Operations assign, start and complete the work. Every status change is validated by a single workflow service, recorded in an audit log, and announced to the right people through queued notifications.

Built as the Quantix Global Laravel internship take-home assignment.

## Stack

- Laravel 13, PHP 8.3
- SQLite (works on MySQL and PostgreSQL too; nothing is database-specific)
- Laravel Sanctum for token authentication
- Pest for tests, Pint for formatting, Larastan (level 6) for static analysis
- GitHub Actions runs Pint, Larastan and the tests on every push

## Setup

Requirements: PHP 8.3+ with the `pdo_sqlite` extension, and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite      # on Windows: type nul > database\database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

Notifications are sent by queued jobs. To process them, run a worker in a second terminal:

```bash
php artisan queue:work
```

Or process the waiting jobs once: `php artisan queue:work --stop-when-empty`.

Status notifications are delivered through both the database and email channels. Local development uses
`MAIL_MAILER=log`, so email contents are written to `storage/logs/laravel.log`. Configure the `MAIL_*`
variables in `.env` with an SMTP provider to send real emails.

To verify a local email notification, keep the queue worker running and watch the log in a second
terminal:

```bash
php artisan queue:work
tail -f storage/logs/laravel.log
```

Then trigger a request status change. On Windows PowerShell, use
`Get-Content .\storage\logs\laravel.log -Wait` instead of `tail -f`.

## Seeded accounts

Every password is `password`.

| Email | Role | Notes |
|---|---|---|
| manager@example.com | manager | Manages Nimal and Kamala |
| nimal@example.com | employee | Reports to the manager |
| kamala@example.com | employee | Reports to the manager |
| ops@example.com | operations | |
| ops2@example.com | operations | A second Operations user, for assignment |

The seeder creates 10 requests covering all 8 statuses. It drives each one through the real workflow service, so every request has a genuine audit history. Run it after `migrate:fresh`, because it is not idempotent.

## Running the checks

```bash
php artisan test                                   # tests
vendor/bin/pint --test                             # code style (omit --test to fix)
vendor/bin/phpstan analyse --memory-limit=1G       # static analysis
```

## API overview

All responses are JSON. Authenticate by sending `Authorization: Bearer <token>`.

| Method | Endpoint | Role | Purpose |
|---|---|---|---|
| POST | `/api/login` | Any | Issue an access token |
| GET | `/api/me` | Any | Current user (extra, handy for checking a token) |
| POST | `/api/requests` | Employee | Create a draft; send `"submit": true` to submit it too |
| GET | `/api/requests` | All | List, scoped by role |
| GET | `/api/requests/{id}` | Policy | Details with comments and activity history |
| PUT | `/api/requests/{id}` | Employee | Edit a draft or returned request |
| POST | `/api/requests/{id}/submit` | Employee | Send for manager review |
| POST | `/api/requests/{id}/decision` | Manager | `approve`, `reject` or `return` |
| POST | `/api/requests/{id}/assign` | Operations | Assign an Operations user |
| POST | `/api/requests/{id}/status` | Operations | Move to `in_progress` or `completed` |
| POST | `/api/requests/{id}/comments` | Participants | Add a comment |

List query parameters: `search`, `status`, `sort` (`created_at`, `updated_at`, `title`, `status`; prefix `-` for descending), `per_page` (max 100).

Full API documentation: import [`docs/service-request-api.postman_collection.json`](docs/service-request-api.postman_collection.json) into Postman. Run one of the Login requests first, because it stores the token that every other request uses. The collection has a folder for each role (Employee, Manager, Operations).

### Status lifecycle

```
draft -> submitted -> approved -> assigned -> in_progress -> completed
                   -> rejected (final)
                   -> returned -> submitted (after the employee edits)
```

### Error format

Every error has the same shape:

```json
{ "message": "Cannot move a request from 'draft' to 'completed'.", "code": "invalid_transition", "errors": {} }
```

| Situation | Status | `code` |
|---|---|---|
| Missing or invalid token | 401 | `unauthenticated` |
| Not allowed (wrong role or not your request) | 403 | `forbidden` |
| Not found | 404 | `not_found` |
| Validation failure | 422 | `validation_failed` |
| Illegal status change | 422 | `invalid_transition` |
| Too many login attempts | 429 | `too_many_requests` |

A wrong person gets a 403 from the policy. The right person trying an impossible move gets a 422 from the workflow.

## Assumptions and decisions

The brief is silent on these, so I decided:

- **Who may create:** only employees.
- **Editing:** only the owner, and only in Draft or Returned. Editing at any other time returns 403, because it is a permission question.
- **Who approves:** the manager recorded on the request when it was submitted (a snapshot). Changing someone's manager later does not change the approver of requests already in review. The snapshot is refreshed when a returned request is resubmitted.
- **Submitting needs a manager.** An employee with no manager gets a 422.
- **No self-approval:** a manager can never decide on their own request.
- **Reject and return require a comment.** Approve does not.
- **Operations:** any Operations user can assign, start and complete, not only the assignee. An assignee must have the Operations role.
- **Visibility:** employees see their own requests, managers see requests they are the approver for, and Operations see requests from Approved onward. Viewing a request you may not see returns 403, not 404, which confirms that it exists.
- **Comments:** anyone who can view a request can comment, including after it is closed. Operations cannot comment before approval, because they cannot see the request yet.
- **Default priority** is `medium`.
- **Notifications** (database channel):

| New status | Notified |
|---|---|
| Submitted | The manager |
| Approved, Rejected, Returned | The requester |
| Assigned | The assignee and the requester |
| In progress, Completed | The requester |

## Where to look in the code

| Concern | Location |
|---|---|
| Status rules | `app/Enums/RequestStatus.php` |
| Transitions, audit log, locking | `app/Services/RequestWorkflow.php` |
| Who may do what | `app/Policies/ServiceRequestPolicy.php` |
| Validation | `app/Http/Requests/` |
| Response shapes | `app/Http/Resources/` |
| Error format | `bootstrap/app.php` |
| Notifications | `app/Jobs/SendRequestStatusNotification.php` |
| Seed data | `database/seeders/DatabaseSeeder.php` |

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the reasoning behind the main decisions.

## What I would do next

- Full-text search. The current `LIKE '%term%'` cannot use an index, which is fine at this size.
- File attachments, SLA due dates with an overdue flag, and rate limiting beyond login.
- An OpenAPI file generated from the code.
- Docker or Sail setup.
- Database CHECK constraints for rules the schema can express (for example, an assignee must exist once a request is assigned).

## AI usage and references
I used Claude as a pair-programming mentor. It proposed the structure and drafted the migrations, workflow service, policies, endpoints, seeders and tests step by step. I created and ran each file, ran Pint, Larastan and the tests at every step, fixed the failures that came up, and committed the work myself.

Packages: Laravel, Laravel Sanctum, Pest, Larastan, Laravel Pint.
