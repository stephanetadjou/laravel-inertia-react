<?php

use App\Jobs\SendWelcomeMessageJob;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('dispatches the welcome message job for an unknown contact using a utility template', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    MessageTemplate::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'bienvenue_opt_in',
        'category' => 'utility',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->post("/contacts/{$contact->id}/send-welcome", [
        'template_name' => 'bienvenue_opt_in',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Queue::assertPushed(SendWelcomeMessageJob::class, fn ($job) => $job->contact->is($contact) && $job->templateName === 'bienvenue_opt_in');
});

it('refuses a marketing-category template for the welcome message', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    MessageTemplate::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'vente_privee',
        'category' => 'marketing',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->post("/contacts/{$contact->id}/send-welcome", [
        'template_name' => 'vente_privee',
    ]);

    $response->assertSessionHasErrors('template_name');
    Queue::assertNotPushed(SendWelcomeMessageJob::class);
});

it('refuses to welcome a contact that already has a documented consent', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 't', 'category' => 'utility', 'is_active' => true]);

    $response = $this->actingAs($user)->post("/contacts/{$contact->id}/send-welcome", ['template_name' => 't']);

    $response->assertSessionHas('error');
});

it('refuses to welcome a contact twice', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create([
        'store_id' => $store->id,
        'consent_status' => 'unknown',
        'welcome_message_sent_at' => now(),
    ]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 't', 'category' => 'utility', 'is_active' => true]);

    $response = $this->actingAs($user)->post("/contacts/{$contact->id}/send-welcome", ['template_name' => 't']);

    $response->assertSessionHas('error');
});

it('refuses to welcome a contact belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id, 'consent_status' => 'unknown']);

    $this->actingAs($user)->post("/contacts/{$foreignContact->id}/send-welcome", ['template_name' => 't'])->assertStatus(403);
});

it('dispatches a bulk welcome send, silently skipping ineligible contacts', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 't', 'category' => 'utility', 'is_active' => true]);

    $eligibleA = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    $eligibleB = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    $alreadyOptIn = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'opt_in']);
    $alreadyWelcomed = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown', 'welcome_message_sent_at' => now()]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id, 'consent_status' => 'unknown']);

    $response = $this->actingAs($user)->post('/contacts/send-welcome-bulk', [
        'contact_ids' => [$eligibleA->id, $eligibleB->id, $alreadyOptIn->id, $alreadyWelcomed->id, $foreignContact->id],
        'template_name' => 't',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Queue::assertPushed(SendWelcomeMessageJob::class, 2);
    Queue::assertPushed(SendWelcomeMessageJob::class, fn ($job) => $job->contact->is($eligibleA));
    Queue::assertPushed(SendWelcomeMessageJob::class, fn ($job) => $job->contact->is($eligibleB));
});

it('marks the contact as welcomed once the job sends the template successfully', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200),
    ]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);

    (new SendWelcomeMessageJob($contact, 'bienvenue_opt_in'))->handle();

    expect($contact->fresh()->welcome_message_sent_at)->not->toBeNull();
    expect($contact->fresh()->consent_status)->toBe('unknown');
});

it('does not mark the contact as welcomed when the whatsapp send fails', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid number']], 400),
    ]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);

    (new SendWelcomeMessageJob($contact, 'bienvenue_opt_in'))->handle();

    expect($contact->fresh()->welcome_message_sent_at)->toBeNull();
});
