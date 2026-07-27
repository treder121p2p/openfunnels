# Product Requirements: Automation Studio

## Status

Implemented on July 27, 2026. This document defines the delivered first production release of Automation Studio and remains the source of truth for its runtime, security, and product boundaries.

## Product Summary

Automation Studio connects OpenFunnels' existing funnels, contacts, email, webhooks, and opportunities into durable workflows.

The primary product loop is:

1. A visitor submits a funnel form.
2. OpenFunnels records the contact and submission immediately.
3. A matching workflow enrolls the contact.
4. Queue-backed steps send follow-up, wait, branch, update CRM records, or call an external service.
5. The owner can inspect every run and retry or cancel failed work.

The first release targets self-hosted solo operators, coaches, consultants, service businesses, and small agencies that need reliable lead follow-up without assembling several SaaS tools.

## Why This Feature Is Next

OpenFunnels already owns the key records required for useful automation:

- Funnel submissions and attribution.
- Contacts and lifecycle status.
- Opportunity pipelines, stages, values, and outcomes.
- Laravel mail delivery.
- HTTP webhooks.
- A database-backed queue.

These capabilities previously operated separately, and some lead notifications executed synchronously during public form submission. Automation Studio now makes them configurable while moving non-critical work away from the visitor-facing request.

OpenFunnels should implement a smaller, observable, self-hosted core rather than matching every competitor action in the first release.

## User Outcomes

Users can:

1. Create a workflow from scratch or a starter recipe.
2. Select one trigger and optionally narrow it to a funnel, pipeline, stage, status, or form.
3. Build a sequence containing actions, waits, and yes/no conditions.
4. Save drafts without affecting the active workflow.
5. Validate and publish an immutable workflow version.
6. Choose whether a contact may enter once, after a prior run completes, or for every distinct source event.
7. Inspect active, waiting, completed, failed, and cancelled runs.
8. See which step ran, what it changed, and why it failed.
9. Retry a safely retryable failed run or cancel an unfinished run.
10. Send marketing email without bypassing an unsubscribed contact.

## Success Measures

The initial release should make the following measurable:

- Percentage of users with a published funnel who activate at least one workflow.
- Median time from workflow creation to first successful publish.
- Event-to-enrollment latency, excluding intentional wait steps.
- Run completion, cancellation, suppression, and failure rates.
- Failure rate by trigger and action type.
- Number of manual retries.
- Number of duplicate enrollments prevented by database constraints.

Initial quality targets:

- Recording an automation event adds no external network call to the source request.
- A queued event normally begins matching within 60 seconds when a worker is healthy.
- The same workflow version cannot enroll twice for the same source event.
- A lost delayed job is recoverable by the scheduled sweeper.
- Publishing invalid, cyclic, unreachable, or cross-account definitions is impossible.
- Paused workflows accept no new runs.

## Release Scope

### Triggers

The first release supports:

- `funnel.form_submitted`
    - Filters: funnel, form ID, new versus repeat contact, and submitted field comparisons.
- `contact.created`
    - Filters: source, source funnel, status, and tags.
- `contact.status_changed`
    - Filters: from status and to status.
- `opportunity.created`
    - Filters: pipeline, stage, source, status, and value comparisons.
- `opportunity.stage_changed`
    - Filters: pipeline, from stage, and to stage.
- `opportunity.status_changed`
    - Filters: pipeline, from status, to status, and value comparisons.

Only one trigger starts a workflow version. Multiple trigger filters use AND logic in the first release.

### Workflow Steps

The first release supports:

- `send_email`
    - Subject, body, purpose, reply-to, and whitelisted merge fields.
- `wait`
    - Fixed minutes, hours, or days, from 1 minute through 365 days.
- `condition`
    - A yes/no branch using contact, submission, funnel, event, or opportunity values.
- `update_contact`
    - Change lifecycle status or add/remove tags.
- `create_opportunity`
    - Create a contact-linked opportunity in an owned pipeline and stage.
- `move_opportunity`
    - Move the event opportunity or the contact's newest matching open opportunity.
- `notify_owner`
    - Send an internal email to the account owner.
- `webhook`
    - POST a signed JSON event to an allowed external URL.
- `end`
    - Explicitly finish a branch.

### Enrollment Policies

Each workflow chooses one:

- `once_per_contact`: the contact may enter the workflow only once across all versions.
- `after_completion`: a new event may enroll the contact only when no active or waiting run exists.
- `every_event`: each distinct source event may create one run.

Every policy still deduplicates repeated delivery of the same source event.

### Workflow Status

- `draft`: never enrolls contacts.
- `active`: enrolls against its published version.
- `paused`: accepts no new enrollments; existing runs continue.
- `archived`: hidden from the default list and accepts no new enrollments.

Pausing does not silently cancel in-flight work. Runs are cancelled explicitly.

### Starter Recipes

Code-defined, versioned recipes avoid adding another persistence system in the first release:

- New lead welcome and owner alert.
- Funnel lead to opportunity.
- Qualified contact follow-up.
- High-value opportunity notification.
- Won opportunity thank-you.

Creating from a recipe copies a normal editable draft. Recipes are not live dependencies.

## Email Consent And Suppression

Automation email must not become an ungoverned bulk-mail path.

- Workflow email is classified as `marketing` or `transactional`.
- Marketing email includes a signed unsubscribe URL and is suppressed for contacts whose email preference is `unsubscribed` or `bounced`.
- The contact record exposes `unknown`, `subscribed`, `unsubscribed`, and `bounced` email preference states.
- A reserved funnel field name, `marketing_consent`, can record explicit subscription when its submitted value is affirmative.
- An unsubscribe request is public, signed, idempotent, and scoped to the funnel owner's account.
- Transactional email may be sent to `unknown` or `unsubscribed` contacts only when the workflow author explicitly selects transactional purpose. This choice is visible in run history.
- The product does not claim legal compliance for the operator. Documentation must explain consent and sender obligations.

Open and click tracking are not part of this release.

## Deliberate Non-Goals

The first release does not include:

- SMS, MMS, WhatsApp, voice, or social messaging.
- Calendar booking or appointment triggers.
- Payment or checkout triggers.
- Recurring schedules, cron triggers, or stale-record polling triggers.
- Loops, arbitrary code, user-authored expressions, or more than two condition branches.
- Parallel branches or branch merging.
- AI workflow generation.
- A free-position infinite canvas.
- Team assignment, round robin, roles, or multi-workspace behavior.
- Shared workflow marketplace or imports from competitors.
- Email broadcasts, list campaigns, advanced deliverability analytics, opens, or clicks.
- Exactly-once guarantees for external email or HTTP providers.

These constraints keep the engine understandable and make loops, fan-out, and abuse less likely.

## UX And Information Architecture

### Navigation

Add `Automations` to the main sidebar after `Opportunities`.

### Automation Index

The index shows:

- Name and optional description.
- Draft, active, paused, or archived state.
- Trigger summary.
- Active published version.
- Runs in the last seven days.
- Completion and failure counts.
- Last run time.
- Actions to create, edit, duplicate, pause/resume, archive, and inspect runs.

Filters include status, trigger type, and search.

The empty state offers starter recipes before a blank workflow.

### Workflow Editor

Use a vertical, scroll-contained sequence instead of adding a graph dependency in the first release.

- The trigger is fixed at the top.
- Steps appear as cards connected vertically.
- Add buttons appear between steps.
- Steps are reorderable with the existing `@dnd-kit` packages.
- Selecting a step opens a configuration sheet.
- A condition renders nested Yes and No columns, each ending explicitly.
- The header shows saved state, validation errors, active version, and Publish.
- Draft autosave uses an integer revision and rejects stale writes with HTTP 409, following the existing funnel autosave pattern.
- Undo and redo operate on local draft history.
- Publish presents a validation summary and creates an immutable version.

The first release limits a definition to:

- 50 total nodes.
- Five nested conditions.
- One trigger.
- No cycles.
- No disconnected nodes.
- No branch without a reachable end.

### Run History

Users can filter by workflow, status, contact, event type, and date.

Run detail shows:

- Contact and source record links.
- Workflow version and trigger payload summary.
- Current node and next scheduled time.
- A chronological step timeline.
- Attempt count, duration, suppression reason, safe output metadata, and sanitized error.
- Retry when the failure is classified retryable.
- Cancel for queued, running, or waiting runs.

Raw secrets, authorization headers, full email bodies, and unrestricted submitted PII must not appear in logs.

### Dry-Run Test

The editor can simulate a draft against an owned contact and optional opportunity:

- Trigger filters and conditions are evaluated.
- The path and merge-field output are shown.
- Email, webhook, notification, and CRM mutations are not executed.
- Test runs are clearly marked and do not affect production metrics.

## Definition Contract

Draft and published definitions use a versioned JSON contract shared by PHP and TypeScript.

```json
{
  "schema_version": 1,
  "trigger": {
    "type": "funnel.form_submitted",
    "config": {
      "funnel_id": 12
    }
  },
  "start_node_id": "01J_NODE_A",
  "nodes": [
    {
      "id": "01J_NODE_A",
      "type": "send_email",
      "config": {
        "purpose": "marketing",
        "subject": "Thanks, {{ contact.name }}",
        "body": "Here is what happens next."
      },
      "next_node_id": "01J_NODE_B"
    },
    {
      "id": "01J_NODE_B",
      "type": "condition",
      "config": {
        "field": "contact.status",
        "operator": "equals",
        "value": "qualified"
      },
      "yes_node_id": "01J_NODE_C",
      "no_node_id": "01J_NODE_D"
    }
  ]
}
```

Node IDs are stable opaque strings. The server is authoritative for validation.

Supported first-release condition operators:

- `equals`
- `not_equals`
- `contains`
- `not_contains`
- `is_empty`
- `is_not_empty`
- `greater_than`
- `greater_than_or_equal`
- `less_than`
- `less_than_or_equal`

Operators are restricted by field type. User-provided PHP, JavaScript, regex, SQL, or template expressions are never evaluated.

### Merge Fields

Allow only documented paths:

- `contact.id`, `contact.name`, `contact.email`, `contact.phone`, `contact.status`
- `funnel.id`, `funnel.name`, `funnel.slug`
- `submission.form_id`
- `submission.fields.<key>`
- `submission.attribution.<utm key>`
- `opportunity.id`, `opportunity.title`, `opportunity.value`, `opportunity.status`
- `pipeline.name`, `stage.name`
- `event.occurred_at`
- `unsubscribe_url`

Unknown merge fields fail publish validation. Values are escaped for their output context.

## Persistence Design

Use database-agnostic Laravel migrations compatible with SQLite tests.

### `automation_workflows`

- `id`
- `user_id`
- `name`
- `description`, nullable
- `status`
- `enrollment_policy`
- `draft_definition`, JSON
- `revision`, unsigned integer
- `active_version_id`, nullable
- timestamps
- indexes on `(user_id, status)` and `(user_id, updated_at)`

Names need not be unique; IDs are the stable identity.

### `automation_workflow_versions`

- `id`
- `workflow_id`
- `version`, unsigned integer
- `definition`, JSON
- `checksum`
- `published_at`
- timestamps
- unique `(workflow_id, version)`
- unique `(workflow_id, checksum)` to prevent publishing an unchanged duplicate

Published versions are immutable. Runs always reference a version, never the mutable draft.

### `automation_events`

This is the transactional outbox.

- ULID or UUID primary key
- `user_id`
- `event_type`
- nullable `contact_id`, `funnel_id`, `submission_id`, and `opportunity_id`
- `payload`, JSON containing only required change data
- nullable `causation_run_id`
- `causation_depth`
- `status`: pending, dispatching, processed, failed
- `attempts`
- `available_at`, `processed_at`, and nullable sanitized `last_error`
- timestamps
- indexes on `(status, available_at)`, `(user_id, event_type)`, and source foreign keys

The event is inserted in the same transaction as the source mutation. Network calls never occur in that transaction.

### `automation_runs`

- ULID or UUID primary key
- `user_id`
- `workflow_id`
- `workflow_version_id`
- `automation_event_id`
- nullable `contact_id`, `funnel_id`, `submission_id`, and `opportunity_id`
- `status`: queued, running, waiting, completed, failed, cancelled
- `current_node_id`, nullable
- `context`, JSON snapshot containing safe scalar context
- `started_at`, `next_resume_at`, `finished_at`, and nullable `last_error`
- timestamps
- unique `(workflow_version_id, automation_event_id)`
- indexes on `(user_id, status)`, `(workflow_id, created_at)`, and `(status, next_resume_at)`

Enrollment-policy constraints are enforced in the matcher inside a transaction. The event/version unique key is the final duplicate guard.

### `automation_step_runs`

- ULID or UUID primary key
- `automation_run_id`
- `node_id`
- `node_type`
- `status`: queued, running, waiting, completed, suppressed, failed, cancelled
- `attempt`
- nullable `scheduled_for`, `started_at`, and `finished_at`
- `input_summary`, `output_summary`, JSON
- nullable sanitized `error_code` and `error_message`
- `idempotency_key`
- timestamps
- unique `(automation_run_id, node_id)`
- unique `idempotency_key`

Loops are out of scope, so a node executes at most once in a run.

### `contact_email_preferences`

- `id`
- `user_id`
- `contact_id`
- `status`: unknown, subscribed, unsubscribed, bounced
- nullable `source`, `consented_at`, and `unsubscribed_at`
- timestamps
- unique `(user_id, contact_id)`

Account scoping is retained even though contacts are already user-owned, making suppression queries explicit and safe.

## Backend Architecture

### Domain Event Recorder

Add a small `AutomationEventRecorder` service. Domain services call it inside their existing transaction after a meaningful mutation.

Do not hide all behavior in broad Eloquent observers. Explicit recording makes old/new values, ownership, causation, and tests predictable.

Source mutations that must be integrated:

- Lead capture transaction.
- Contact creation and status changes.
- Manual and automated opportunity creation.
- Opportunity stage and status changes.

Automation actions that mutate contacts or opportunities use the same domain services as controllers so downstream events are consistent.

### Durable Dispatch

1. A source transaction inserts an `automation_events` row.
2. After commit, `DispatchAutomationEvent` is queued.
3. The job locks the event, loads active workflows for its owner and event type, evaluates filters, and creates deduplicated runs.
4. `ExecuteAutomationRun` is queued for each new run.
5. A scheduled command scans pending events and re-dispatches any job lost between commit and queue insertion.

Use an `automations` queue name in production documentation. The existing development worker can continue processing the default queue unless the dev command is updated to listen to both.

### Execution Engine

`ExecuteAutomationRun`:

1. Acquires a per-run overlap lock and row lock.
2. exits for completed, failed, or cancelled runs.
3. Loads the immutable version definition.
4. Resolves the current node.
5. Claims or resumes its step-run record.
6. Dispatches the node to a registered handler.
7. Stores a sanitized result and advances the run.
8. Requeues immediately for normal actions, with a delay for waits, or on the selected condition branch.
9. Completes when no next node remains after an explicit end.

Use typed registries:

- `TriggerRegistry`
- `ActionRegistry`
- `ConditionFieldRegistry`
- `MergeFieldResolver`
- `WorkflowDefinitionValidator`

Avoid controller conditionals that grow with every new action.

Suggested action contract:

```php
interface WorkflowAction
{
    public function type(): string;

    public function validate(array $config, User $owner): array;

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult;

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult;
}
```

### Wait Recovery

A wait step stores `next_resume_at` before dispatching the delayed job. A scheduled sweeper requeues overdue waiting runs. This handles purged or lost delayed queue jobs.

### Retry Semantics

- Jobs use bounded attempts and exponential backoff.
- Validation, missing owned resources, suppression, and invalid configuration are permanent outcomes.
- Timeouts, connection failures, and provider 5xx responses are retryable.
- HTTP 4xx responses other than 408 and 429 are permanent.
- Retry creates a new attempt on the existing step record; it does not create a second run.
- External delivery is at-least-once. Webhooks receive a stable `Idempotency-Key` header.
- The UI must not claim exactly-once email or HTTP delivery.

### Causation And Loop Protection

Workflow mutations can emit new events. Every derived event records:

- The originating run ID.
- Causation depth.

The engine rejects a new derived event after depth five and does not reenroll the same workflow from its own causal chain by default. This protects against `status A -> status B -> status A` workflow loops.

### Existing Lead Notifications

The existing environment-configured new-lead email and webhook remain functional during the first release to avoid a breaking change.

They should be moved to queued jobs so public lead capture does not wait for SMTP or HTTP. Documentation should warn that enabling equivalent workflow actions can produce duplicate notifications. A later migration can offer to replace the legacy settings with a recipe.

## API And Route Plan

Authenticated and verified routes:

- `GET /automations` — index and filters.
- `POST /automations` — create blank or from recipe.
- `GET /automations/{workflow}/edit` — editor.
- `PUT /automations/{workflow}/autosave` — revision-checked draft save.
- `POST /automations/{workflow}/validate` — server validation and dry-run preparation.
- `POST /automations/{workflow}/simulate` — no-side-effect test.
- `POST /automations/{workflow}/publish` — immutable version and activation.
- `POST /automations/{workflow}/pause`
- `POST /automations/{workflow}/resume`
- `POST /automations/{workflow}/duplicate`
- `DELETE /automations/{workflow}` — archive when history exists.
- `GET /automations/runs` — cross-workflow run history.
- `GET /automations/runs/{run}` — detail.
- `POST /automations/runs/{run}/retry`
- `POST /automations/runs/{run}/cancel`
- `GET /email/unsubscribe/{preference}` — public signed confirmation.
- `POST /email/unsubscribe/{preference}` — public signed opt-out.

Use policies for every workflow and run. Never resolve a pipeline, stage, funnel, contact, or opportunity outside the authenticated owner query.

## Frontend File Plan

Suggested files:

- `resources/js/pages/automations/index.tsx`
- `resources/js/pages/automations/editor.tsx`
- `resources/js/pages/automations/runs.tsx`
- `resources/js/pages/automations/run-detail.tsx`
- `resources/js/pages/email/unsubscribe.tsx`
- `resources/js/components/automations/WorkflowSequence.tsx`
- `resources/js/components/automations/WorkflowNodeCard.tsx`
- `resources/js/components/automations/WorkflowStepSheet.tsx`
- `resources/js/components/automations/TriggerEditor.tsx`
- `resources/js/components/automations/ConditionBranches.tsx`
- `resources/js/components/automations/RunTimeline.tsx`
- `resources/js/stores/automationStore.ts`
- `resources/js/types/automation.ts`

The TypeScript definition must use discriminated unions. Do not use `any` or duplicate divergent node interfaces inside page files.

No new canvas dependency is required for the first release.

## Backend File Plan

Suggested files:

- Models, policies, controllers, and form requests under their standard Laravel directories.
- `app/Services/Automation/AutomationEventRecorder.php`
- `app/Services/Automation/WorkflowDefinitionValidator.php`
- `app/Services/Automation/WorkflowPublisher.php`
- `app/Services/Automation/WorkflowMatcher.php`
- `app/Services/Automation/WorkflowRunner.php`
- registries and typed context/result value objects under `app/Services/Automation`
- action implementations under `app/Services/Automation/Actions`
- trigger implementations under `app/Services/Automation/Triggers`
- `app/Jobs/DispatchAutomationEvent.php`
- `app/Jobs/ExecuteAutomationRun.php`
- `app/Mail/AutomationContactEmail.php`
- `app/Mail/AutomationOwnerNotification.php`

Keep the controllers thin. Definition validation and execution belong in services.

## Webhook Security

Outbound webhook actions are an SSRF boundary.

- Validate the URL at draft save, publish, and execution.
- Allow HTTPS by default.
- Reject credentials embedded in URLs.
- Resolve DNS at execution and reject loopback, link-local, multicast, metadata, private, and reserved addresses.
- Revalidate after redirects or disable redirects.
- Apply short connection and total timeouts and a response-size cap.
- Store optional signing secrets encrypted.
- Send a timestamp, event ID, run ID, and HMAC signature.
- Redact secrets and authorization headers from all logs.
- Provide an explicit self-hosting configuration switch if private-network webhooks are required; keep it disabled by default.

## Limits And Abuse Controls

Configurable defaults:

- 50 nodes per workflow.
- Five condition levels.
- 20 active workflows matching one event.
- Five causal workflow hops.
- 10,000 automation events per account per day before soft throttling.
- One-minute minimum wait.
- 365-day maximum wait.
- 100 KB maximum webhook response read.
- 90-day detailed run-log retention.

Self-hosters may raise limits through config, not by editing application code.

## Data Retention

- Published workflow versions referenced by runs are retained.
- Detailed input/output summaries and processed outbox payloads are pruned after the configured retention period.
- Aggregate workflow counts remain.
- Contact deletion cascades or nulls run source links according to migration behavior, while retaining a minimal non-PII audit record.
- User deletion cascades all workflows, events, runs, and email preferences.

## Demo Sandbox

- Seed one active-looking workflow and representative completed/waiting run history.
- Allow browsing, editing, validation, and dry-run simulation.
- Block publishing, resuming, retrying real runs, external email, and external webhooks.
- Demo cleanup must cascade all automation records.

## Migration And Compatibility

- Existing funnels and contacts receive no active workflow automatically.
- Existing funnel opportunity automation continues to work.
- Existing environment-based lead notification and webhook settings continue to work.
- Queue and scheduler requirements are added to `.env.example` and self-hosting documentation.
- Migration rollback removes automation tables without changing existing funnel, contact, or opportunity data, except separately added email-preference state.

## Implementation Slices

### Slice 1: Runtime Spine

- Migrations, models, relationships, factories, policies, and enums.
- Shared PHP/TypeScript definition schema.
- Server-side definition validator.
- Draft autosave and immutable publishing.
- Event recorder, outbox dispatcher, matcher, deduplication, and run creation.
- Scheduled recovery for pending events.

Exit criteria: a synthetic event creates one run for one active workflow version, and duplicate dispatch creates no duplicate run.

### Slice 2: Execution And CRM

- Run executor, action registry, condition registry, and merge resolver.
- End, wait, condition, update-contact, create-opportunity, and move-opportunity steps.
- Form, contact, and opportunity event integrations.
- Causation tracking, depth limit, pause, cancel, and retry.
- Scheduled recovery for waiting runs.

Exit criteria: a lead submission can create a resumable, branching CRM workflow with complete step history and no network actions.

### Slice 3: Messaging And Webhooks

- Contact and owner email actions.
- Email preferences, consent capture, unsubscribe route, and suppression.
- Secure signed webhook action.
- Queue the legacy lead email and webhook behavior.
- External-action failure classification and safe logging.

Exit criteria: external work never delays public form submission, suppression is enforced, and webhook SSRF tests pass.

### Slice 4: Product UI

- Sidebar, automation index, recipes, and empty states.
- Vertical workflow editor, step sheets, condition branches, autosave conflict handling, validation, and publish.
- Dry-run simulator.
- Run history, run detail, retry, and cancel.
- Contact and opportunity links into relevant run history.

Exit criteria: a non-technical user can build, test, publish, inspect, pause, and diagnose a workflow without editing JSON.

### Slice 5: Hardening And Release

- Demo data and demo restrictions.
- Retention pruning.
- Self-hosting, queue, scheduler, consent, and webhook documentation.
- Full accessibility pass for keyboard step movement, labels, focus, and status announcements.
- Performance tests for event matching and run-history pagination.
- Full backend, frontend, formatting, build, and migration verification.

Exit criteria: all acceptance tests pass on SQLite and the production build succeeds.

## Acceptance Test Matrix

### Ownership And Authorization

- Users cannot view or mutate another user's workflows, versions, runs, contacts, funnels, pipelines, stages, or opportunities.
- Cross-account IDs fail validation at draft save, publish, simulation, and execution.
- Public unsubscribe links affect only the intended contact/account preference.

### Drafts And Publishing

- A draft never receives events.
- Autosave increments revision and rejects stale revisions.
- Invalid node config, missing targets, cycles, disconnected nodes, excessive depth, and missing ends fail publish.
- Publishing creates an immutable version and atomically makes it active.
- Editing after publish changes only the draft.
- Pausing stops new enrollment while existing runs continue.

### Event Reliability

- Source mutation and outbox event commit together.
- Rolled-back source mutations create no event.
- Repeated dispatcher execution creates no duplicate run.
- The pending-event sweeper recovers an event with no queued job.
- More than the configured workflow-match limit is handled predictably and logged.

### Execution

- Each node executes at most once per run.
- Wait steps resume at or after their target time.
- The waiting-run sweeper recovers a lost delayed job.
- Conditions choose only one branch.
- Cancelled runs do not execute another step.
- Retryable failures retry with backoff; permanent failures do not loop.
- A stale pipeline or stage fails the step safely without modifying another account.
- Causation depth and self-reentry protection stop circular workflows.

### Email

- Merge fields render from the owned run context.
- Unknown merge fields fail publish.
- Marketing email to an unsubscribed or bounced contact is suppressed.
- Signed unsubscribe is idempotent.
- Transactional purpose is visible in audit history.
- Demo users cannot deliver external email.

### Webhooks

- Payload includes stable event/run IDs and signature.
- Private, loopback, metadata, embedded-credential, and DNS-rebinding targets are rejected.
- Redirects cannot bypass address validation.
- Timeout, 429, and 5xx failures are retryable.
- Other 4xx failures are permanent.
- Secrets and response bodies are redacted or truncated.
- Demo users cannot send webhooks.

### Regression

- Public lead capture remains successful when workflow matching or execution later fails.
- Existing lead notification and funnel opportunity settings continue working.
- Contact and opportunity CRUD behavior remains authorized and test-covered.
- User and demo deletion cleanly remove automation data.

## Verification Commands

During implementation, run focused tests per slice and finish with:

```bash
composer test
pnpm run test
pnpm run types
pnpm run lint
pnpm run format:check
./vendor/bin/pint --test
pnpm run build
git diff --check
```

Also verify:

- Fresh migration and rollback on SQLite.
- Queue execution with the database driver, not only the synchronous test driver.
- Scheduler recovery for pending events and waiting runs.
- A real local SMTP/log mailer path.
- Webhook address validation without making tests depend on public network access.

## Definition Of Done

Automation Studio is complete only when:

- A user can build and publish the scoped workflow types through the UI.
- Events are durable, enrollment is deduplicated, and runs are resumable.
- Waits, conditions, CRM actions, email suppression, and secure webhooks work.
- Every run has an understandable timeline.
- External failures never break lead capture.
- Policies and execution-time ownership checks prevent cross-account access.
- Demo mode cannot cause external side effects.
- Queue, scheduler, consent, and webhook operations are documented for self-hosters.
- Tests cover the acceptance matrix and all required project checks pass.
