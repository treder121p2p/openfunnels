<?php

use App\Mail\AutomationMessage;
use App\Models\AutomationEvent;
use App\Models\AutomationWorkflow;
use App\Models\ContactEmailPreference;
use App\Models\User;
use App\Services\Automation\AutomationEventRecorder;
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
    $workflow->update(['draft_definition' => automationEndDefinition('opportunity.created')]);

    expect($workflow->activeVersion->fresh()->definition)->toBe($published);
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
    app(AutomationEventRecorder::class)->record($user, 'contact.created', contact: $contact);
    expect($workflow->runs()->count())->toBe(0);

    $this->actingAs($user)->post(route('automations.resume', $workflow))->assertRedirect();
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

    Http::assertSent(fn ($request) => $request->url() === 'https://8.8.8.8/hooks'
        && filled($request->header('Idempotency-Key')[0] ?? null)
        && str_starts_with($request->header('X-OpenFunnels-Signature')[0] ?? '', 'sha256=')
        && $request['run_id']
        && $request['event_id']);
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

    expect($user->contacts()->where('email', 'still-captured@example.com')->exists())->toBeTrue()
        ->and($workflow->runs()->firstOrFail()->status)->toBe('failed')
        ->and($user->automationEvents()->where('event_type', 'funnel.form_submitted')->firstOrFail()->status)->toBe('pending');
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
