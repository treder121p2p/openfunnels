<?php

use App\Mail\NewLeadCaptured;
use App\Models\Contact;
use App\Models\Funnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createLeadCaptureFunnelFor(User $user, array $overrides = []): Funnel
{
    return $user->funnels()->create([
        'name' => $overrides['name'] ?? 'Lead Capture Funnel',
        'slug' => $overrides['slug'] ?? 'lead-capture-funnel',
        'description' => $overrides['description'] ?? null,
        'content' => $overrides['content'] ?? ['sections' => []],
        'settings' => $overrides['settings'] ?? ['backgroundColor' => '#ffffff', 'maxWidth' => '1200px'],
        'status' => $overrides['status'] ?? 'published',
        'is_published' => $overrides['is_published'] ?? true,
        'views' => $overrides['views'] ?? 0,
        'conversions' => $overrides['conversions'] ?? 0,
        'conversion_rate' => $overrides['conversion_rate'] ?? 0,
        'published_at' => $overrides['published_at'] ?? now(),
    ]);
}

function createContactRecordFor(User $user, Funnel $funnel, array $overrides = []): Contact
{
    $contact = $user->contacts()->create([
        'funnel_id' => $funnel->id,
        'email' => $overrides['email'] ?? fake()->unique()->safeEmail(),
        'name' => $overrides['name'] ?? fake()->name(),
        'phone' => $overrides['phone'] ?? null,
        'source' => $overrides['source'] ?? 'funnel_form',
        'status' => $overrides['status'] ?? 'new',
        'metadata' => $overrides['metadata'] ?? ['submission_count' => 1],
        'last_submitted_at' => $overrides['last_submitted_at'] ?? now(),
    ]);

    if (isset($overrides['created_at'])) {
        $contact->forceFill(['created_at' => $overrides['created_at']])->saveQuietly();
    }

    return $contact;
}

test('published funnel form submissions create contacts and increment conversions', function () {
    Mail::fake();

    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'phone' => '+15555550123',
        'form_id' => 'block-form-1',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('contacts', [
        'user_id' => $user->id,
        'funnel_id' => $funnel->id,
        'email' => 'ada@example.com',
        'name' => 'Ada Lovelace',
        'phone' => '+15555550123',
        'source' => 'funnel_form',
        'status' => 'new',
    ]);
    $this->assertDatabaseHas('contact_submissions', [
        'funnel_id' => $funnel->id,
        'form_id' => 'block-form-1',
    ]);

    Mail::assertSent(NewLeadCaptured::class);

    $funnel->refresh();

    expect($funnel->conversions)->toBe(1);
    expect((float) $funnel->conversion_rate)->toBe(0.0);
});

test('lead capture can send a webhook payload', function () {
    Mail::fake();
    Http::fake();
    config(['services.lead_capture.webhook_url' => 'https://hooks.example.test/leads']);

    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'form_id' => 'demo-form',
        'fields' => ['email' => 'ada@example.com'],
    ])->assertRedirect();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/leads'
        && $request['event'] === 'lead.captured'
        && $request['contact']['email'] === 'ada@example.com'
        && $request['funnel']['id'] === $funnel->id);
});

test('repeat submissions update the existing contact for the account', function () {
    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'email' => 'ada@example.com',
        'form_id' => 'first-form',
    ])->assertRedirect();

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada',
        'email' => 'ADA@example.com',
        'form_id' => 'second-form',
    ])->assertRedirect();

    expect($user->contacts()->where('email', 'ada@example.com')->count())->toBe(1);

    $contact = $user->contacts()->where('email', 'ada@example.com')->first();

    expect($contact->name)->toBe('Ada');
    expect(data_get($contact->metadata, 'submission_count'))->toBe(2);
    expect($funnel->fresh()->conversions)->toBe(2);
});

test('configurable form fields are preserved on each submission', function () {
    Mail::fake();

    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'form_id' => 'qualification-form',
        'fields' => [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'company_size' => '11-50',
            'project_details' => 'We need a configurable lead funnel.',
            'utm_campaign' => 'summer-launch',
        ],
    ])->assertRedirect();

    $submission = $user->contacts()->firstOrFail()->submissions()->firstOrFail();

    expect($submission->form_id)->toBe('qualification-form')
        ->and($submission->fields)->toMatchArray([
            'company_size' => '11-50',
            'project_details' => 'We need a configurable lead funnel.',
            'utm_campaign' => 'summer-launch',
        ]);
});

test('lead capture stores bounded campaign attribution', function () {
    Mail::fake();
    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'email' => 'campaign@example.com',
        'attribution' => [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'product-launch',
            'referrer' => 'https://example.com/article',
        ],
    ])->assertRedirect();

    $submission = $user->contacts()->firstOrFail()->submissions()->firstOrFail();

    expect($submission->attribution)->toMatchArray([
        'utm_source' => 'newsletter',
        'utm_campaign' => 'product-launch',
    ])->and(data_get($submission->contact->metadata, 'last_attribution.utm_medium'))->toBe('email');
});

test('configurable form field payloads are bounded', function () {
    $funnel = createLeadCaptureFunnelFor(User::factory()->create());

    $this->post(route('funnels.leads.store', $funnel), [
        'email' => 'ada@example.com',
        'fields' => ['notes' => str_repeat('a', 5001)],
    ])->assertSessionHasErrors('fields.notes');

    $this->assertDatabaseMissing('contacts', ['email' => 'ada@example.com']);
});

test('guests cannot submit leads to unpublished funnels', function () {
    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user, [
        'status' => 'draft',
        'is_published' => false,
        'published_at' => null,
    ]);

    $this->post(route('funnels.leads.store', $funnel), [
        'email' => 'lead@example.com',
    ])->assertForbidden();

    $this->assertDatabaseMissing('contacts', [
        'email' => 'lead@example.com',
    ]);
});

test('contacts page lists captured leads for the authenticated user', function () {
    Mail::fake();

    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])->assertRedirect();

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('contacts.index'))
        ->assertOk()
        ->assertSee('Ada Lovelace')
        ->assertSee('ada@example.com');
});

test('contact detail shows submissions and supports notes and status updates', function () {
    Mail::fake();

    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'form_id' => 'demo-form',
        'fields' => ['email' => 'ada@example.com', 'plan' => 'pro'],
    ])->assertRedirect();

    $contact = $user->contacts()->where('email', 'ada@example.com')->firstOrFail();

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('contacts.show', $contact))
        ->assertOk()
        ->assertSee('Ada Lovelace')
        ->assertSee('demo-form')
        ->assertSee('pro');

    $this->actingAs($user)
        ->patch(route('contacts.update', $contact), ['status' => 'qualified'])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('contacts.notes.store', $contact), ['note' => 'Follow up tomorrow.'])
        ->assertRedirect();

    $contact->refresh();

    expect($contact->status)->toBe('qualified');
    expect(data_get($contact->metadata, 'notes.0.body'))->toBe('Follow up tomorrow.');
});

test('contacts can be searched and filtered by status funnel and captured date', function () {
    $user = User::factory()->create();
    $matchingFunnel = createLeadCaptureFunnelFor($user, [
        'name' => 'Demo Requests',
        'slug' => 'demo-requests',
    ]);
    $otherFunnel = createLeadCaptureFunnelFor($user, [
        'name' => 'Newsletter',
        'slug' => 'newsletter',
    ]);
    $otherUser = User::factory()->create();
    $otherUsersFunnel = createLeadCaptureFunnelFor($otherUser, [
        'name' => 'Private Funnel',
        'slug' => 'private-funnel',
    ]);

    createContactRecordFor($user, $matchingFunnel, [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'phone' => '+15555550111',
        'status' => 'qualified',
        'created_at' => now()->setDate(2026, 7, 20),
    ]);
    createContactRecordFor($user, $otherFunnel, [
        'name' => 'Grace Lee',
        'email' => 'grace.lee@example.com',
        'status' => 'qualified',
        'created_at' => now()->setDate(2026, 7, 20),
    ]);
    createContactRecordFor($user, $matchingFunnel, [
        'name' => 'Alan Turing',
        'email' => 'alan@example.com',
        'status' => 'new',
        'created_at' => now()->setDate(2026, 7, 20),
    ]);
    createContactRecordFor($otherUser, $otherUsersFunnel, [
        'name' => 'Grace Hopper',
        'email' => 'other-grace@example.com',
        'status' => 'qualified',
        'created_at' => now()->setDate(2026, 7, 20),
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('contacts.index', [
            'search' => 'Grace',
            'status' => 'qualified',
            'funnel_id' => $matchingFunnel->id,
            'date_from' => '2026-07-20',
            'date_to' => '2026-07-20',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('contacts')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.email', 'grace@example.com')
            ->where('contacts.total', 1)
            ->where('filters.search', 'Grace')
            ->where('filters.status', 'qualified')
            ->where('filters.funnel_id', (string) $matchingFunnel->id)
            ->where('filters.date_from', '2026-07-20')
            ->where('filters.date_to', '2026-07-20')
            ->has('filterOptions.funnels', 2));
});

test('contact filters are validated and retained across pagination', function () {
    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user);

    foreach (range(1, 26) as $index) {
        createContactRecordFor($user, $funnel, [
            'email' => "qualified-{$index}@example.com",
            'status' => 'qualified',
        ]);
    }

    $this->actingAs($user)
        ->get(route('contacts.index', ['status' => 'qualified']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('contacts.total', 26)
            ->where('filters.status', 'qualified')
            ->where('contacts.next_page_url', fn (string $url) => str_contains($url, 'status=qualified')));

    $this->actingAs($user)
        ->get(route('contacts.index', [
            'status' => 'not-a-status',
            'funnel_id' => 'invalid',
            'date_from' => '2026-07-21',
            'date_to' => '2026-07-20',
        ]))
        ->assertSessionHasErrors(['status', 'funnel_id', 'date_to']);
});

test('contacts csv export uses the active filters and protects spreadsheet cells', function () {
    $user = User::factory()->create();
    $funnel = createLeadCaptureFunnelFor($user, [
        'name' => 'Demo Requests',
        'slug' => 'csv-demo-requests',
    ]);
    $otherUser = User::factory()->create();
    $otherUsersFunnel = createLeadCaptureFunnelFor($otherUser, [
        'name' => 'Other Account Funnel',
        'slug' => 'other-account-funnel',
    ]);

    foreach (range(1, 26) as $index) {
        createContactRecordFor($user, $funnel, [
            'name' => $index === 1 ? '=HYPERLINK("https://example.test")' : "Qualified Lead {$index}",
            'email' => "export-{$index}@example.com",
            'phone' => $index === 1 ? '+15555550123' : null,
            'status' => 'qualified',
            'metadata' => [
                'submission_count' => $index,
                'notes' => [['body' => 'Private note must not be exported.']],
            ],
            'created_at' => now()->setDate(2026, 7, 20),
        ]);
    }
    createContactRecordFor($user, $funnel, [
        'name' => 'Unqualified Lead',
        'email' => 'excluded-status@example.com',
        'status' => 'new',
        'created_at' => now()->setDate(2026, 7, 20),
    ]);
    createContactRecordFor($otherUser, $otherUsersFunnel, [
        'name' => 'Other Account Lead',
        'email' => 'excluded-owner@example.com',
        'status' => 'qualified',
        'created_at' => now()->setDate(2026, 7, 20),
    ]);

    $response = $this->actingAs($user)
        ->get(route('contacts.export', [
            'status' => 'qualified',
            'funnel_id' => $funnel->id,
            'date_from' => '2026-07-20',
            'date_to' => '2026-07-20',
        ]))
        ->assertOk()
        ->assertDownload('openfunnels-contacts-'.today()->format('Y-m-d').'.csv')
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];

    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);
    $formulaRow = collect($rows)->first(fn (array $row) => ($row[1] ?? null) === 'export-1@example.com');

    expect($csv)
        ->toStartWith("\xEF\xBB\xBF")
        ->not->toContain('excluded-status@example.com')
        ->not->toContain('excluded-owner@example.com')
        ->not->toContain('Private note must not be exported.')
        ->and($rows)->toHaveCount(27)
        ->and($rows[0])->toBe([
            "\xEF\xBB\xBFName",
            'Email',
            'Phone',
            'Status',
            'Source',
            'Funnel',
            'Submission Count',
            'Last Activity',
            'Created At',
        ])
        ->and($formulaRow)->not->toBeNull()
        ->and($formulaRow[0])->toStartWith("'=")
        ->and($formulaRow[2])->toBe("'+15555550123");
});

test('empty contact exports remain valid and exports require authentication', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('contacts.export'))
        ->assertOk();

    $csv = $response->streamedContent();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];

    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    expect($rows)->toHaveCount(1);

    auth()->logout();

    $this->get(route('contacts.export'))
        ->assertRedirect(route('login'));
});
