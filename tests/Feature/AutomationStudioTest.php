<?php

use App\Mail\AutomationMessage;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationWorkflow;
use App\Models\ContactEmailPreference;
use App\Models\User;
use App\Services\Automation\AutomationEventRecorder;
use App\Services\Automation\Exceptions\RetryableAutomationException;
use App\Services\Automation\WebhookSigner;
use App\Services\Automation\WorkflowDefinitionValidator;
use App\Services\Automation\WorkflowMatcher;
use App\Services\Automation\WorkflowPublisher;
use App\Services\Automation\WorkflowRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function automationEndDefinition(string $trigger = 'contact.created'): array
{
    $end = (string) Str::ulid();

    return [
        'schema_version' => 1,
        'trigger' => ['type' => $trigger, 'config' => []],
        'start_node_id' => $end,
        'nodes' => [
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ];
}

function automationWorkflowFor(User $user, array $definition, array $overrides = []): AutomationWorkflow
{
    return $user->automationWorkflows()->create([
        'name' => $overrides['name'] ?? 'Lead follow-up',
        'description' => $overrides['description'] ?? 'Test workflow',
        'status' => 'draft',
        'enrollment_policy' => $overrides['enrollment_policy'] ?? 'every_event',
        'draft_definition' => $definition,
        'revision' => 1,
    ]);
}

function automationContactFor(User $user, array $overrides = [])
{
    return $user->contacts()->create([
        'email' => $overrides['email'] ?? 'lead@example.com',
        'name' => $overrides['name'] ?? 'Lead Person',
        'source' => $overrides['source'] ?? 'manual',
        'status' => $overrides['status'] ?? 'new',
        'tags' => [],
        'metadata' => [],
    ]);
}

test('workflow drafts autosave with optimistic concurrency and publish immutable versions', function () {
    $user = User::factory()->create();
    $workflow = automationWorkflowFor($user, automationEndDefinition());

    $this->actingAs($user)
        ->putJson(route('automations.autosave', $workflow), [
            'revision' => 1,
            'name' => 'First automation',
            'description' => 'A saved draft',
            'enrollment_policy' => 'once_per_contact',
            'definition' => $workflow->draft_definition,
        ])
        ->assertOk()
        ->assertJsonPath('revision', 2);

    expect($workflow->fresh()->trigger_type)->toBe('contact.created');

    $this->actingAs($user)
        ->putJson(route('automations.autosave', $workflow), [
            'revision' => 1,
            'name' => 'Stale write',
            'description' => null,
            'enrollment_policy' => 'every_event',
            'definition' => $workflow->draft_definition,
        ])
        ->assertConflict();

    $this->actingAs($user)
        ->post(route('automations.publish', $workflow))
        ->assertRedirect();

    $workflow->refresh();
    expect($workflow->status)->toBe('active')
        ->and($workflow->activeVersion)->not->toBeNull()
        ->and($workflow->activeVersion->version)->toBe(1)
        ->and($workflow->activeVersion->definition)->toEqual($workflow->draft_definition);

    $published = $workflow->activeVersion->definition;
    $this->actingAs($user)
        ->putJson(route('automations.autosave', $workflow), [
            'revision' => 2,
            'name' => 'First automation',
            'description' => 'A changed trigger draft',
            'enrollment_policy' => 'every_event',
            'definition' => automationEndDefinition('opportunity.created'),
        ])
        ->assertOk()
        ->assertJsonPath('revision', 3);

    expect($workflow->fresh()->trigger_type)->toBe('contact.created')
        ->and($workflow->activeVersion->fresh()->enrollment_policy)->toBe('once_per_contact')
        ->and($workflow->activeVersion->fresh()->definition)->toBe($published);
});

test('publishing a changed enrollment policy creates a new immutable version', function () {
    $user = User::factory()->create();
    $workflow = automationWorkflowFor($user, automationEndDefinition(), ['enrollment_policy' => 'once_per_contact']);
    $first = app(WorkflowPublisher::class)->publish($workflow);

    $workflow->update(['enrollment_policy' => 'every_event', 'revision' => 2]);
    $second = app(WorkflowPublisher::class)->publish($workflow->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($second->version)->toBe(2)
        ->and($first->fresh()->enrollment_policy)->toBe('once_per_contact')
        ->and($second->enrollment_policy)->toBe('every_event');
});

test('publishing rejects a draft snapshot that changed during validation', function () {
    $user = User::factory()->create();
    $workflow = automationWorkflowFor($user, automationEndDefinition());
    $staleSnapshot = $workflow->fresh();
    $workflow->update([
        'draft_definition' => automationEndDefinition('opportunity.created'),
        'revision' => 2,
    ]);

    expect(fn () => app(WorkflowPublisher::class)->publish($staleSnapshot))
        ->toThrow(ValidationException::class);

    expect($workflow->fresh()->active_version_id)->toBeNull();
});

test('long workflow names duplicate safely and published workflows without history can be deleted', function () {
    $user = User::factory()->create();
    $workflow = automationWorkflowFor($user, automationEndDefinition(), ['name' => str_repeat('W', 255)]);
    app(WorkflowPublisher::class)->publish($workflow);

    $this->actingAs($user)
        ->post(route('automations.duplicate', $workflow))
        ->assertRedirect();

    $copy = $user->automationWorkflows()->where('id', '!=', $workflow->id)->firstOrFail();
    expect(mb_strlen($copy->name))->toBeLessThanOrEqual(255);

    $this->actingAs($user)
        ->delete(route('automations.destroy', $workflow))
        ->assertRedirect();

    $this->assertDatabaseMissing('automation_workflows', ['id' => $workflow->id]);
});

test('event outbox dispatch is deduplicated and completes a workflow run', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $workflow = automationWorkflowFor($user, automationEndDefinition());
    app(WorkflowPublisher::class)->publish($workflow);

    $event = DB::transaction(fn () => app(AutomationEventRecorder::class)->record(
        $user,
        'contact.created',
        contact: $contact,
        payload: ['source' => 'test'],
    ));

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($workflow->runs()->count())->toBe(1);

    $run = $workflow->runs()->firstOrFail();
    expect($run->status)->toBe('completed')
        ->and($run->steps)->toHaveCount(1)
        ->and($run->steps->first()->node_type)->toBe('end');

    app(WorkflowMatcher::class)->dispatch($event);
    expect($workflow->runs()->count())->toBe(1);
});

test('contact enrollment policies prevent inappropriate re-entry', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $once = automationWorkflowFor($user, automationEndDefinition(), ['enrollment_policy' => 'once_per_contact']);
    app(WorkflowPublisher::class)->publish($once);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    expect($once->runs()->count())->toBe(1);

    $afterCompletion = automationWorkflowFor($user, automationEndDefinition(), [
        'name' => 'After completion',
        'enrollment_policy' => 'after_completion',
    ]);
    app(WorkflowPublisher::class)->publish($afterCompletion);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    expect($afterCompletion->runs()->count())->toBe(2);
});

test('event outbox soft throttles an account after its configured daily limit', function () {
    Queue::fake();
    config(['automation.event_daily_limit' => 1]);
    $user = User::factory()->create();

    AutomationEvent::create([
        'user_id' => $user->id,
        'event_type' => 'contact.created',
        'payload' => [],
        'status' => 'processed',
        'available_at' => now(),
        'processed_at' => now(),
    ]);

    $event = app(AutomationEventRecorder::class)->record($user, 'contact.created');

    expect($event->available_at->isAfter(now()))->toBeTrue();
});

test('wait steps persist and recover before continuing', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $wait = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $wait,
        'nodes' => [
            ['id' => $wait, 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'minutes'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    $run = $workflow->runs()->firstOrFail();

    expect($run->status)->toBe('waiting')
        ->and($run->next_resume_at)->not->toBeNull();

    $this->travel(2)->minutes();
    app(WorkflowRunner::class)->execute($run->fresh());

    expect($run->fresh()->status)->toBe('completed')
        ->and($run->steps()->where('status', 'completed')->count())->toBe(2);
});

test('conditions and contact actions mutate crm data and record causation safely', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $condition = (string) Str::ulid();
    $update = (string) Str::ulid();
    $yesEnd = (string) Str::ulid();
    $noEnd = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $condition,
        'nodes' => [
            [
                'id' => $condition,
                'type' => 'condition',
                'config' => ['field' => 'contact.status', 'operator' => 'equals', 'value' => 'new'],
                'yes_node_id' => $update,
                'no_node_id' => $noEnd,
            ],
            [
                'id' => $update,
                'type' => 'update_contact',
                'config' => ['status' => 'qualified', 'add_tags' => ['automation-qualified'], 'remove_tags' => []],
                'next_node_id' => $yesEnd,
            ],
            ['id' => $yesEnd, 'type' => 'end', 'config' => []],
            ['id' => $noEnd, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    expect($contact->fresh()->status)->toBe('qualified')
        ->and($contact->fresh()->tags)->toContain('automation-qualified')
        ->and($workflow->runs()->firstOrFail()->status)->toBe('completed')
        ->and($user->automationEvents()->where('event_type', 'contact.status_changed')->exists())->toBeTrue();
});

test('workflow crm actions create and move an idempotent opportunity', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $pipeline = $user->pipelines()->create(['name' => 'Automation sales', 'currency' => 'USD']);
    $firstStage = $pipeline->stages()->create(['name' => 'New', 'position' => 0, 'probability' => 10]);
    $secondStage = $pipeline->stages()->create(['name' => 'Qualified', 'position' => 1, 'probability' => 60]);
    $create = (string) Str::ulid();
    $move = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $create,
        'nodes' => [
            [
                'id' => $create,
                'type' => 'create_opportunity',
                'config' => [
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $firstStage->id,
                    'title' => '{{ contact.name }} automation deal',
                    'value' => 2500,
                ],
                'next_node_id' => $move,
            ],
            [
                'id' => $move,
                'type' => 'move_opportunity',
                'config' => ['pipeline_id' => $pipeline->id, 'stage_id' => $secondStage->id],
                'next_node_id' => $end,
            ],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    $opportunity = $user->opportunities()->firstOrFail();
    expect($opportunity->pipeline_stage_id)->toBe($secondStage->id)
        ->and($opportunity->value_cents)->toBe(250000)
        ->and($opportunity->title)->toBe('Lead Person automation deal')
        ->and($opportunity->activities()->where('type', 'stage_moved')->exists())->toBeTrue()
        ->and($workflow->runs()->firstOrFail()->status)->toBe('completed');
});

test('paused workflows reject new enrollments while existing versions remain resumable', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $workflow = automationWorkflowFor($user, automationEndDefinition());
    app(WorkflowPublisher::class)->publish($workflow);

    $this->actingAs($user)->post(route('automations.pause', $workflow))->assertRedirect();
    $this->actingAs($user)->post(route('automations.pause', $workflow))->assertStatus(422);
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    expect($workflow->runs()->count())->toBe(0);

    $this->actingAs($user)->post(route('automations.resume', $workflow))->assertRedirect();
    $this->actingAs($user)->post(route('automations.resume', $workflow))->assertStatus(422);
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    expect($workflow->runs()->count())->toBe(1)
        ->and($workflow->fresh()->status)->toBe('active');
});

test('workflow validation rejects cycles and disconnected steps', function () {
    $user = User::factory()->create();
    $first = (string) Str::ulid();
    $orphan = (string) Str::ulid();
    $definition = [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $first,
        'nodes' => [
            ['id' => $first, 'type' => 'wait', 'config' => ['amount' => 1, 'unit' => 'minutes'], 'next_node_id' => $first],
            ['id' => $orphan, 'type' => 'end', 'config' => []],
        ],
    ];

    $errors = app(WorkflowDefinitionValidator::class)->validate($definition, $user);

    expect(implode(' ', $errors))->toContain('cycles')
        ->and(implode(' ', $errors))->toContain('disconnected');
});

test('workflow validation rejects malformed trigger and action configuration', function () {
    $user = User::factory()->create();
    $otherPipeline = $user->pipelines()->create(['name' => 'Other', 'currency' => 'USD']);
    $otherStage = $otherPipeline->stages()->create(['name' => 'Other stage', 'position' => 0, 'probability' => 10]);
    $pipeline = $user->pipelines()->create(['name' => 'Sales', 'currency' => 'USD']);
    $pipeline->stages()->create(['name' => 'New', 'position' => 0, 'probability' => 10]);
    $update = (string) Str::ulid();
    $create = (string) Str::ulid();
    $end = (string) Str::ulid();
    $definition = [
        'schema_version' => 1,
        'trigger' => [
            'type' => 'opportunity.stage_changed',
            'config' => [
                'pipeline_id' => $pipeline->id,
                'stage_id' => $otherStage->id,
                'field' => 'not.allowed',
                'operator' => 'approximately',
                'value' => ['nested'],
            ],
        ],
        'start_node_id' => $update,
        'nodes' => [
            [
                'id' => $update,
                'type' => 'update_contact',
                'config' => ['add_tags' => [['nested']], 'remove_tags' => []],
                'next_node_id' => $create,
            ],
            [
                'id' => $create,
                'type' => 'create_opportunity',
                'config' => [
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $pipeline->stages()->value('id'),
                    'value' => 100000000,
                    'title' => str_repeat('x', 256),
                ],
                'next_node_id' => $end,
            ],
            ['id' => $end, 'type' => 'end', 'config' => 'invalid'],
        ],
    ];

    $errors = implode(' ', app(WorkflowDefinitionValidator::class)->validate($definition, $user));

    expect($errors)->toContain('trigger stage is not available')
        ->toContain('unsupported field')
        ->toContain('unsupported operator')
        ->toContain('scalar value')
        ->toContain('configuration object')
        ->toContain('invalid tags')
        ->toContain('99,999,999.99')
        ->toContain('title under 255');
});

test('marketing email respects contact suppression', function () {
    Mail::fake();
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    ContactEmailPreference::create([
        'user_id' => $user->id,
        'contact_id' => $contact->id,
        'status' => 'unsubscribed',
        'source' => 'test',
        'unsubscribed_at' => now(),
    ]);
    $email = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $email,
        'nodes' => [
            [
                'id' => $email,
                'type' => 'send_email',
                'config' => ['purpose' => 'marketing', 'subject' => 'Hello {{ contact.name }}', 'body' => 'Welcome'],
                'next_node_id' => $end,
            ],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    Mail::assertNotSent(AutomationMessage::class);
    expect($workflow->runs()->firstOrFail()->steps()->where('status', 'suppressed')->count())->toBe(1)
        ->and($workflow->runs()->firstOrFail()->status)->toBe('completed');
});

test('automation plain text email preserves signed link query parameters', function () {
    $rendered = view('emails.automation-message', [
        'messageBody' => 'Unsubscribe: https://example.com/unsubscribe?expires=123&signature=abc',
    ])->render();

    expect($rendered)->toContain('expires=123&signature=abc')
        ->not->toContain('&amp;signature');
});

test('signed webhooks deliver stable identifiers and signatures', function () {
    Http::fake(['https://8.8.8.8/*' => Http::response('', 204)]);
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $webhook = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $webhook,
        'nodes' => [
            ['id' => $webhook, 'type' => 'webhook', 'config' => ['url' => 'https://8.8.8.8/hooks'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    Http::assertSent(function ($request) use ($workflow): bool {
        $timestamp = $request->header('X-OpenFunnels-Timestamp')[0] ?? '';
        $expected = 'sha256='.app(WebhookSigner::class)->signature($workflow->id, $timestamp, $request->body());

        return $request->url() === 'https://8.8.8.8/hooks'
            && filled($request->header('Idempotency-Key')[0] ?? null)
            && hash_equals($expected, $request->header('X-OpenFunnels-Signature')[0] ?? '')
            && $request['run_id']
            && $request['event_id'];
    });
});

test('webhook redirects fail permanently and cannot be retried manually', function () {
    Http::fake(['https://8.8.8.8/*' => Http::response('', 302, ['Location' => 'https://example.com'])]);
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $webhook = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $webhook,
        'nodes' => [
            ['id' => $webhook, 'type' => 'webhook', 'config' => ['url' => 'https://8.8.8.8/redirect'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);

    $run = $workflow->runs()->firstOrFail();
    expect($run->status)->toBe('failed')
        ->and($run->steps()->where('error_code', 'webhook_redirect')->exists())->toBeTrue();

    $this->actingAs($user)
        ->post(route('automation-runs.retry', $run))
        ->assertStatus(422);
});

test('retryable actions persist their backoff so recovery cannot run them early', function () {
    Http::fake(['https://8.8.8.8/*' => Http::response('', 503)]);
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $webhook = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $webhook,
        'nodes' => [
            ['id' => $webhook, 'type' => 'webhook', 'config' => ['url' => 'https://8.8.8.8/retry'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    $version = app(WorkflowPublisher::class)->publish($workflow);
    $event = AutomationEvent::create([
        'user_id' => $user->id,
        'event_type' => 'contact.created',
        'contact_id' => $contact->id,
        'payload' => [],
        'status' => 'processed',
        'available_at' => now(),
        'processed_at' => now(),
    ]);
    $run = AutomationRun::create([
        'user_id' => $user->id,
        'workflow_id' => $workflow->id,
        'workflow_version_id' => $version->id,
        'automation_event_id' => $event->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'current_node_id' => $webhook,
        'context' => [],
    ]);

    expect(fn () => app(WorkflowRunner::class)->execute($run))
        ->toThrow(RetryableAutomationException::class);

    expect($run->fresh()->status)->toBe('queued')
        ->and($run->fresh()->next_resume_at?->isFuture())->toBeTrue()
        ->and($run->steps()->firstOrFail()->scheduled_for?->isFuture())->toBeTrue();

    app(WorkflowRunner::class)->execute($run->fresh());
    Http::assertSentCount(1);
});

test('recently running steps are not executed twice and stale steps can recover', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $workflow = automationWorkflowFor($user, automationEndDefinition());
    $version = app(WorkflowPublisher::class)->publish($workflow);
    $event = AutomationEvent::create([
        'user_id' => $user->id,
        'event_type' => 'contact.created',
        'contact_id' => $contact->id,
        'payload' => [],
        'status' => 'processed',
        'available_at' => now(),
        'processed_at' => now(),
    ]);
    $run = AutomationRun::create([
        'user_id' => $user->id,
        'workflow_id' => $workflow->id,
        'workflow_version_id' => $version->id,
        'automation_event_id' => $event->id,
        'contact_id' => $contact->id,
        'status' => 'running',
        'current_node_id' => $version->definition['start_node_id'],
        'context' => [],
        'started_at' => now(),
    ]);
    $step = $run->steps()->create([
        'node_id' => $version->definition['start_node_id'],
        'node_type' => 'end',
        'status' => 'running',
        'attempt' => 1,
        'started_at' => now(),
        'idempotency_key' => hash('sha256', "{$run->id}:{$version->definition['start_node_id']}"),
    ]);

    app(WorkflowRunner::class)->execute($run);
    expect($step->fresh()->attempt)->toBe(1)
        ->and($run->fresh()->status)->toBe('running');

    $this->travel(6)->minutes();
    app(WorkflowRunner::class)->execute($run->fresh());

    expect($step->fresh()->attempt)->toBe(2)
        ->and($run->fresh()->status)->toBe('completed');
});

test('unsafe webhooks and cross account crm targets fail definition validation', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $pipeline = $other->pipelines()->create(['name' => 'Private', 'currency' => 'USD']);
    $stage = $pipeline->stages()->create(['name' => 'Private stage', 'position' => 0, 'probability' => 10]);
    $create = (string) Str::ulid();
    $webhook = (string) Str::ulid();
    $end = (string) Str::ulid();
    $definition = [
        'schema_version' => 1,
        'trigger' => ['type' => 'contact.created', 'config' => []],
        'start_node_id' => $create,
        'nodes' => [
            [
                'id' => $create,
                'type' => 'create_opportunity',
                'config' => ['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'value' => 0],
                'next_node_id' => $webhook,
            ],
            ['id' => $webhook, 'type' => 'webhook', 'config' => ['url' => 'https://127.0.0.1/hooks'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ];

    $errors = app(WorkflowDefinitionValidator::class)->validate($definition, $user, true);

    expect($errors)->toContain("Opportunity step {$create} needs an owned pipeline.")
        ->and(implode(' ', $errors))->toContain('Private, loopback, link-local, and reserved');
});

test('published funnel submissions can run crm workflows without blocking capture', function () {
    Mail::fake();
    $user = User::factory()->create();
    $funnel = $user->funnels()->create([
        'name' => 'Automation Funnel',
        'slug' => 'automation-funnel',
        'content' => ['sections' => []],
        'settings' => [],
        'status' => 'published',
        'is_published' => true,
        'published_at' => now(),
    ]);
    $update = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'funnel.form_submitted', 'config' => ['funnel_id' => $funnel->id]],
        'start_node_id' => $update,
        'nodes' => [
            ['id' => $update, 'type' => 'update_contact', 'config' => ['status' => 'qualified'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    $this->post(route('funnels.leads.store', $funnel), [
        'email' => 'qualified@example.com',
        'fields' => ['marketing_consent' => 'yes'],
    ])->assertRedirect()->assertSessionHas('success');

    app(WorkflowMatcher::class)->dispatch(
        $user->automationEvents()->where('event_type', 'funnel.form_submitted')->firstOrFail(),
    );
    $contact = $user->contacts()->where('email', 'qualified@example.com')->firstOrFail();
    expect($contact->status)->toBe('qualified')
        ->and($contact->emailPreference->status)->toBe('subscribed')
        ->and($workflow->runs()->firstOrFail()->status)->toBe('completed');
});

test('automation delivery failures never break a public funnel submission', function () {
    Mail::fake();
    Http::fake(['https://8.8.8.8/*' => Http::response('Unavailable', 503)]);
    $user = User::factory()->create();
    $funnel = $user->funnels()->create([
        'name' => 'Reliable Capture Funnel',
        'slug' => 'reliable-capture-funnel',
        'content' => ['sections' => []],
        'settings' => [],
        'status' => 'published',
        'is_published' => true,
        'published_at' => now(),
    ]);
    $webhook = (string) Str::ulid();
    $end = (string) Str::ulid();
    $workflow = automationWorkflowFor($user, [
        'schema_version' => 1,
        'trigger' => ['type' => 'funnel.form_submitted', 'config' => ['funnel_id' => $funnel->id]],
        'start_node_id' => $webhook,
        'nodes' => [
            ['id' => $webhook, 'type' => 'webhook', 'config' => ['url' => 'https://8.8.8.8/unavailable'], 'next_node_id' => $end],
            ['id' => $end, 'type' => 'end', 'config' => []],
        ],
    ]);
    app(WorkflowPublisher::class)->publish($workflow);

    $this->post(route('funnels.leads.store', $funnel), [
        'email' => 'still-captured@example.com',
    ])->assertRedirect()->assertSessionHas('success');

    Http::assertNothingSent();
    expect($workflow->runs()->count())->toBe(0);

    $event = $user->automationEvents()->where('event_type', 'funnel.form_submitted')->firstOrFail();
    expect(fn () => app(WorkflowMatcher::class)->dispatch($event))
        ->toThrow(RetryableAutomationException::class);

    expect($user->contacts()->where('email', 'still-captured@example.com')->exists())->toBeTrue()
        ->and($workflow->runs()->firstOrFail()->status)->toBe('failed')
        ->and($event->fresh()->status)->toBe('pending');
});

test('unsubscribe links are signed scoped and idempotent', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $preference = ContactEmailPreference::create([
        'user_id' => $user->id,
        'contact_id' => $contact->id,
        'status' => 'subscribed',
        'source' => 'test',
    ]);
    $url = URL::temporarySignedRoute('email.unsubscribe.show', now()->addHour(), ['preference' => $preference->id]);

    $this->withoutVite()->get($url)->assertOk();
    $this->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();

    expect($preference->fresh()->status)->toBe('unsubscribed')
        ->and($preference->fresh()->unsubscribed_at)->not->toBeNull();

    $this->get(route('email.unsubscribe.show', $preference))->assertForbidden();
});

test('automation resources and runs remain isolated by owner and cascade on account deletion', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $contact = automationContactFor($user);
    $workflow = automationWorkflowFor($user, automationEndDefinition());
    app(WorkflowPublisher::class)->publish($workflow);
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    $run = $workflow->runs()->firstOrFail();

    $this->actingAs($other)->get(route('automations.edit', $workflow))->assertForbidden();
    $this->actingAs($other)->get(route('automation-runs.show', $run))->assertForbidden();
    $this->actingAs($other)->post(route('automations.publish', $workflow))->assertForbidden();
    $this->actingAs($other)->post(route('automation-runs.cancel', $run))->assertForbidden();

    $user->delete();

    $this->assertDatabaseMissing('automation_workflows', ['id' => $workflow->id]);
    $this->assertDatabaseMissing('automation_runs', ['id' => $run->id]);
    $this->assertDatabaseMissing('automation_events', ['id' => $run->automation_event_id]);
});

test('deleting a contact removes personal data from retained automation history', function () {
    $user = User::factory()->create();
    $contact = automationContactFor($user);
    $workflow = automationWorkflowFor($user, automationEndDefinition());
    app(WorkflowPublisher::class)->publish($workflow);
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    $run = $workflow->runs()->firstOrFail();

    $contact->delete();

    expect($run->fresh()->contact_id)->toBeNull()
        ->and($run->fresh()->context)->toBe([]);
});
