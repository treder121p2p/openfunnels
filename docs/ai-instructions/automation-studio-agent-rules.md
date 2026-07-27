# Automation Studio Agent Rules

Read `docs/prds/automation-studio.md` completely before changing Automation Studio code.

## Scope Rules

- Implement the PRD in slices. Do not add SMS, calendars, payments, arbitrary code, loops, parallel branches, AI generation, or an infinite canvas to the first release.
- Do not add a graph-editor dependency for the first release. Use the existing React, Radix, Tailwind, Zustand, and `@dnd-kit` stack.
- Preserve existing funnel opportunity automation and environment-configured lead notifications until an explicit migration replaces them.

## Runtime Rules

- Record automation events through an explicit service inside the same database transaction as the source mutation.
- Never perform email, webhook, or other network I/O inside a public lead-capture request or database transaction.
- Published workflow versions are immutable. Runs must reference a version, never a mutable draft.
- Deduplicate enrollment with database constraints as well as application checks.
- Wait steps must persist their wake time before dispatching a delayed job, and a scheduled sweeper must recover lost jobs.
- Use bounded retries, backoff, cancellation checks, causation depth, and self-reentry protection.
- Do not promise exactly-once delivery to external providers. Use stable idempotency keys and document at-least-once behavior.

## Data And Authorization Rules

- Resolve all referenced contacts, funnels, pipelines, stages, opportunities, workflows, and runs through the owning user.
- Repeat ownership validation during execution; publish-time validation alone is insufficient because referenced records can later change or be deleted.
- Keep migrations SQLite-compatible and database-agnostic.
- Store only the context required to execute and audit a run. Redact secrets, headers, full message bodies, unrestricted response bodies, and unnecessary PII.
- Cascade user-owned automation data when a user is deleted.

## Definition Rules

- Define canonical discriminated unions in `resources/js/types/automation.ts`.
- Keep the PHP validator and TypeScript types aligned through the documented `schema_version`.
- Use opaque stable node IDs.
- Reject cycles, disconnected nodes, unknown node types, invalid field/operator combinations, missing branch ends, excessive depth, and cross-account references on publish.
- Never evaluate user-authored PHP, JavaScript, SQL, regex, or arbitrary template expressions.
- Escape merge values for their output context.

## External Action Rules

- Marketing email must respect unsubscribe and bounce suppression.
- Demo users must not deliver email or webhooks.
- Treat webhook URLs as an SSRF boundary: validate resolved addresses on every attempt, reject credentials and unsafe networks by default, control redirects, cap time and response size, sign requests, and encrypt secrets.
- Do not expose raw provider errors or secrets in the UI.

## Testing Rules

- Use `Queue::fake()`, `Mail::fake()`, and `Http::fake()` for focused behavior, but also add database-queue integration coverage for persistence and recovery.
- Test transaction rollback, duplicate dispatch, stale references, cancellation races, delayed-job recovery, causation loops, suppression, SSRF, policies, demo restrictions, and user deletion.
- Prefer focused Pest tests over broad snapshots.
- Run the complete verification command list in the PRD before handoff.
