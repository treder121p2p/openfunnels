<?php

use App\Models\Contact;
use App\Models\Funnel;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function crmPipelineFor(User $user, string $name = 'Sales Pipeline'): Pipeline
{
    $pipeline = $user->pipelines()->create([
        'name' => $name,
        'currency' => 'USD',
    ]);
    $pipeline->stages()->createMany([
        ['name' => 'New Lead', 'position' => 0, 'probability' => 10],
        ['name' => 'Qualified', 'position' => 1, 'probability' => 50],
    ]);

    return $pipeline->load('stages');
}

function crmFunnelFor(User $user, string $name = 'CRM Funnel'): Funnel
{
    return $user->funnels()->create([
        'name' => $name,
        'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
        'content' => ['sections' => []],
        'settings' => [],
        'status' => 'published',
        'is_published' => true,
        'published_at' => now(),
    ]);
}

function crmContactFor(User $user, Funnel $funnel, string $email = 'lead@example.com'): Contact
{
    return $user->contacts()->create([
        'funnel_id' => $funnel->id,
        'email' => $email,
        'name' => 'Qualified Lead',
        'source' => 'funnel_form',
        'status' => 'qualified',
        'last_submitted_at' => now(),
    ]);
}

test('users can create configurable pipelines and see owner scoped board forecasts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('pipelines.store'), [
            'name' => 'Consulting Sales',
            'currency' => 'usd',
            'stages' => [
                ['name' => 'Discovery', 'probability' => 20],
                ['name' => 'Proposal', 'probability' => 60],
            ],
        ])
        ->assertRedirect();

    $pipeline = $user->pipelines()->with('stages')->firstOrFail();
    $funnel = crmFunnelFor($user);
    $contact = crmContactFor($user, $funnel);
    $proposal = $pipeline->stages->last();

    $opportunity = $user->opportunities()->create([
        'pipeline_id' => $pipeline->id,
        'pipeline_stage_id' => $proposal->id,
        'contact_id' => $contact->id,
        'funnel_id' => $funnel->id,
        'title' => 'Growth engagement',
        'value_cents' => 120000,
        'status' => 'open',
        'stage_changed_at' => now(),
    ]);

    $otherUser = User::factory()->create();
    $otherPipeline = crmPipelineFor($otherUser, 'Private Pipeline');
    $otherFunnel = crmFunnelFor($otherUser, 'Private Funnel');
    $otherContact = crmContactFor($otherUser, $otherFunnel, 'private@example.com');
    $otherUser->opportunities()->create([
        'pipeline_id' => $otherPipeline->id,
        'pipeline_stage_id' => $otherPipeline->stages->first()->id,
        'contact_id' => $otherContact->id,
        'title' => 'Private deal',
        'value_cents' => 999999,
        'status' => 'open',
    ]);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('opportunities.index', ['pipeline_id' => $pipeline->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('opportunities')
            ->has('pipelines', 1)
            ->has('pipelines.0.stages', 2)
            ->has('opportunities', 1)
            ->where('opportunities.0.id', $opportunity->id)
            ->where('stats.open_value_cents', 120000)
            ->where('stats.weighted_value_cents', 72000)
            ->where('stats.won_value_cents', 0));
});

test('opportunities support contact linking stage movement values outcomes and activity history', function () {
    $user = User::factory()->create();
    $pipeline = crmPipelineFor($user);
    $funnel = crmFunnelFor($user);
    $contact = crmContactFor($user, $funnel);

    $this->actingAs($user)
        ->post(route('opportunities.store'), [
            'contact_id' => $contact->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $pipeline->stages->first()->id,
            'funnel_id' => $funnel->id,
            'title' => 'Implementation project',
            'value' => '1200.25',
            'expected_close_date' => '2026-08-15',
        ])
        ->assertRedirect();

    $opportunity = $user->opportunities()->firstOrFail();
    expect($opportunity->value_cents)->toBe(120025)
        ->and($opportunity->activities()->where('type', 'created')->exists())->toBeTrue();

    $this->actingAs($user)
        ->patch(route('opportunities.update', $opportunity), [
            'pipeline_stage_id' => $pipeline->stages->last()->id,
            'value' => '1500',
            'status' => 'won',
            'title' => 'Implementation and support',
            'expected_close_date' => null,
        ])
        ->assertRedirect();

    $opportunity->refresh();

    expect($opportunity->pipeline_stage_id)->toBe($pipeline->stages->last()->id)
        ->and($opportunity->value_cents)->toBe(150000)
        ->and($opportunity->status)->toBe('won')
        ->and($opportunity->closed_at)->not->toBeNull()
        ->and($opportunity->activities()->pluck('type'))->toContain('stage_moved', 'value_changed', 'status_changed');

    $this->actingAs($user)
        ->patch(route('opportunities.update', $opportunity), ['status' => 'open'])
        ->assertRedirect();

    expect($opportunity->fresh()->closed_at)->toBeNull();

    $otherUser = User::factory()->create();
    $this->actingAs($otherUser)
        ->patch(route('opportunities.update', $opportunity), ['status' => 'lost'])
        ->assertForbidden();
});

test('stage and pipeline deletion preserve opportunities safely', function () {
    $user = User::factory()->create();
    $pipeline = crmPipelineFor($user);
    $funnel = crmFunnelFor($user);
    $contact = crmContactFor($user, $funnel);
    $sourceStage = $pipeline->stages->first();
    $destinationStage = $pipeline->stages->last();
    $opportunity = $user->opportunities()->create([
        'pipeline_id' => $pipeline->id,
        'pipeline_stage_id' => $sourceStage->id,
        'contact_id' => $contact->id,
        'title' => 'Movable opportunity',
        'value_cents' => 50000,
        'status' => 'open',
    ]);

    $this->actingAs($user)
        ->delete(route('pipeline-stages.destroy', $sourceStage))
        ->assertSessionHasErrors('move_to_stage_id');

    $this->actingAs($user)
        ->delete(route('pipeline-stages.destroy', $sourceStage), ['move_to_stage_id' => $destinationStage->id])
        ->assertRedirect();

    expect($opportunity->fresh()->pipeline_stage_id)->toBe($destinationStage->id)
        ->and($opportunity->activities()->where('type', 'stage_moved')->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('pipelines.destroy', $pipeline))
        ->assertSessionHasErrors('pipeline');

    $opportunity->delete();

    $this->actingAs($user)
        ->delete(route('pipelines.destroy', $pipeline))
        ->assertRedirect(route('opportunities.index'));

    $this->assertDatabaseMissing('pipelines', ['id' => $pipeline->id]);
});

test('per funnel automation creates idempotent open opportunities and starts a new deal after closure', function () {
    Mail::fake();
    $user = User::factory()->create();
    $pipeline = crmPipelineFor($user);
    $funnel = crmFunnelFor($user);
    $stage = $pipeline->stages->first();

    $this->actingAs($user)
        ->patch(route('funnels.crm-settings.update', $funnel), [
            'enabled' => true,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'default_value' => '2500',
        ])
        ->assertRedirect();

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])->assertRedirect();
    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])->assertRedirect();

    expect($user->opportunities()->count())->toBe(1);

    $firstOpportunity = $user->opportunities()->firstOrFail();
    expect($firstOpportunity->value_cents)->toBe(250000)
        ->and($firstOpportunity->funnel_id)->toBe($funnel->id);

    $firstOpportunity->update(['status' => 'won', 'closed_at' => now()]);

    $this->post(route('funnels.leads.store', $funnel), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])->assertRedirect();

    expect($user->opportunities()->count())->toBe(2)
        ->and($user->opportunities()->where('status', 'open')->count())->toBe(1);
});

test('disabled or stale crm settings never block lead capture', function () {
    Mail::fake();
    $user = User::factory()->create();
    $pipeline = crmPipelineFor($user);
    $funnel = crmFunnelFor($user);

    $this->post(route('funnels.leads.store', $funnel), ['email' => 'disabled@example.com'])
        ->assertRedirect();
    expect($user->opportunities()->count())->toBe(0);

    $stage = $pipeline->stages->first();
    $this->actingAs($user)->patch(route('funnels.crm-settings.update', $funnel), [
        'enabled' => true,
        'pipeline_id' => $pipeline->id,
        'pipeline_stage_id' => $stage->id,
        'default_value' => 0,
    ])->assertRedirect();

    $this->actingAs($user)
        ->delete(route('pipeline-stages.destroy', $stage))
        ->assertRedirect();

    $this->post(route('funnels.leads.store', $funnel), ['email' => 'stale@example.com'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->opportunities()->count())->toBe(0);
});

test('contact lookup is bounded and isolated to the authenticated owner', function () {
    $user = User::factory()->create();
    $funnel = crmFunnelFor($user);

    foreach (range(1, 21) as $index) {
        crmContactFor($user, $funnel, "owner-{$index}@example.com");
    }

    $otherUser = User::factory()->create();
    $otherFunnel = crmFunnelFor($otherUser, 'Other Funnel');
    crmContactFor($otherUser, $otherFunnel, 'private-contact@example.com');

    $this->actingAs($user)
        ->getJson(route('contacts.lookup'))
        ->assertOk()
        ->assertJsonCount(20, 'contacts')
        ->assertJsonMissing(['email' => 'private-contact@example.com']);
});

test('deleting an account cascades its complete crm graph', function () {
    $user = User::factory()->create();
    $pipeline = crmPipelineFor($user);
    $funnel = crmFunnelFor($user);
    $contact = crmContactFor($user, $funnel);
    $opportunity = $user->opportunities()->create([
        'pipeline_id' => $pipeline->id,
        'pipeline_stage_id' => $pipeline->stages->first()->id,
        'contact_id' => $contact->id,
        'funnel_id' => $funnel->id,
        'title' => 'Cascading deal',
        'value_cents' => 10000,
        'status' => 'open',
    ]);
    $opportunity->recordActivity('created', [], $user->id);
    $funnel->opportunitySetting()->create([
        'enabled' => true,
        'pipeline_id' => $pipeline->id,
        'pipeline_stage_id' => $pipeline->stages->first()->id,
        'default_value_cents' => 10000,
    ]);

    $user->delete();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('pipelines', ['id' => $pipeline->id]);
    $this->assertDatabaseMissing('opportunities', ['id' => $opportunity->id]);
});
