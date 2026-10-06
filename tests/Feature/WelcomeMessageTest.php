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

it('sends the welcome template from the shared Meta account when the store has no credentials of its own', function () {
    config([
        'services.meta_whatsapp.token' => 'shared-token',
        'services.meta_whatsapp.phone_number_id' => '999000111',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_phone_number_id' => null,
        'whatsapp_access_token' => null,
    ]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown', 'phone_e164' => '24162605054']);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'bienvenue', 'language' => 'fr', 'category' => 'utility']);

    (new SendWelcomeMessageJob($contact, 'bienvenue'))->handle();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/999000111/messages')
        && $request->hasHeader('Authorization', 'Bearer shared-token')
        && $request['to'] === '24162605054'
        && $request['template']['name'] === 'bienvenue'
        && $request['template']['language']['code'] === 'fr');
    expect($contact->fresh()->welcome_message_sent_at)->not->toBeNull();
});

it('sends welcomes for every store through the same shared account', function () {
    config([
        'services.meta_whatsapp.token' => 'shared-token',
        'services.meta_whatsapp.phone_number_id' => '999000111',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

    $organization = Organization::factory()->create();
    $contacts = collect(['Adidas', 'Carré', 'Mango', 'Grand Store'])->map(function (string $name) use ($organization) {
        $store = Store::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'whatsapp_phone_number_id' => null,
            'whatsapp_access_token' => null,
        ]);

        return Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);
    });

    $contacts->each(fn ($contact) => (new SendWelcomeMessageJob($contact, 'bienvenue'))->handle());

    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/999000111/messages'));
    $contacts->each(fn ($contact) => expect($contact->fresh()->welcome_message_sent_at)->not->toBeNull());
});

it('does not send anything when neither the store nor the shared account is configured', function () {
    config(['services.meta_whatsapp.token' => null, 'services.meta_whatsapp.phone_number_id' => null]);
    Http::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id, 'whatsapp_phone_number_id' => null, 'whatsapp_access_token' => null]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);

    (new SendWelcomeMessageJob($contact, 'bienvenue'))->handle();

    Http::assertNothingSent();
    expect($contact->fresh()->welcome_message_sent_at)->toBeNull();
});

it('re-checks eligibility when the job runs, never welcoming twice or writing to an opt_out', function (array $stateAtRunTime) {
    Http::fake();

    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']);

    $job = new SendWelcomeMessageJob($contact, 'bienvenue');
    Contact::whereKey($contact->id)->update($stateAtRunTime);

    $job->handle();

    Http::assertNothingSent();
})->with([
    'déjà accueilli' => [['welcome_message_sent_at' => now()]],
    'passé en opt_out' => [['consent_status' => 'opt_out']],
    'passé en opt_in' => [['consent_status' => 'opt_in']],
]);

it('sends the welcome to all new clients of the period across every visible store', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 't', 'category' => 'utility', 'is_active' => true]);

    $stores = Store::factory()->count(4)->create(['organization_id' => $organization->id]);
    $newClients = $stores->map(fn ($store) => Contact::factory()->create(['store_id' => $store->id, 'consent_status' => 'unknown']));
    // Au-delà des 10 affichés sur le tableau de bord : tous doivent partir.
    $newClients = $newClients->merge(Contact::factory()->count(12)->create(['store_id' => $stores[0]->id, 'consent_status' => 'unknown']));

    $oldClient = Contact::factory()->create(['store_id' => $stores[1]->id, 'consent_status' => 'unknown', 'created_at' => now()->subMonths(3)]);
    $optIn = Contact::factory()->create(['store_id' => $stores[1]->id, 'consent_status' => 'opt_in']);
    $optOut = Contact::factory()->create(['store_id' => $stores[1]->id, 'consent_status' => 'opt_out']);
    $welcomed = Contact::factory()->create(['store_id' => $stores[2]->id, 'consent_status' => 'unknown', 'welcome_message_sent_at' => now()]);
    $foreign = Contact::factory()->create(['store_id' => Store::factory()->create(['organization_id' => $otherOrganization->id])->id, 'consent_status' => 'unknown']);

    $this->actingAs($user)->post('/contacts/send-welcome-bulk', [
        'all_pending' => true,
        'from' => now()->subDays(29)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
        'template_name' => 't',
    ])->assertSessionHas('success', "{$newClients->count()} message(s) de bienvenue en cours d'envoi.");

    Queue::assertPushed(SendWelcomeMessageJob::class, $newClients->count());
    foreach ([$oldClient, $optIn, $optOut, $welcomed, $foreign] as $excluded) {
        Queue::assertNotPushed(SendWelcomeMessageJob::class, fn ($job) => $job->contact->is($excluded));
    }
});

it('limits the send-to-all welcome to the stores of a brand manager', function () {
    Queue::fake();

    $organization = Organization::factory()->create();
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 't', 'category' => 'utility', 'is_active' => true]);
    $adidasStore = Store::factory()->create(['organization_id' => $organization->id]);
    $mangoStore = Store::factory()->create(['organization_id' => $organization->id]);
    $adidasClient = Contact::factory()->create(['store_id' => $adidasStore->id, 'consent_status' => 'unknown']);
    Contact::factory()->create(['store_id' => $mangoStore->id, 'consent_status' => 'unknown']);

    $brandManager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de marque')->id,
        'brand_id' => $adidasStore->brand_id,
    ]);

    $this->actingAs($brandManager)->post('/contacts/send-welcome-bulk', ['all_pending' => true, 'template_name' => 't']);

    Queue::assertPushed(SendWelcomeMessageJob::class, 1);
    Queue::assertPushed(SendWelcomeMessageJob::class, fn ($job) => $job->contact->is($adidasClient));
});

it('counts stores relying on the shared account as connected on the dashboard', function () {
    config([
        'services.meta_whatsapp.token' => 'shared-token',
        'services.meta_whatsapp.phone_number_id' => '999000111',
    ]);

    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    Store::factory()->count(2)->create(['organization_id' => $organization->id, 'whatsapp_phone_number_id' => null, 'whatsapp_access_token' => null]);
    Contact::factory()->count(3)->create([
        'store_id' => Store::factory()->create(['organization_id' => $organization->id])->id,
        'consent_status' => 'unknown',
    ]);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->has('stores', 3)
        ->where('stores', fn ($stores) => collect($stores)->every(fn ($store) => $store['connected']))
        ->where('pendingWelcome', 3),
    );
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
