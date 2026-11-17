<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Services\ProspectPipeline;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'beaute', 'status' => Organization::STATUS_ACTIVE, 'plan' => 'gratuit']);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
});

function cardPayload(array $extra = []): array
{
    return ['name' => 'Aïcha Moussavou', 'job_title' => 'Gérante', 'company' => 'Institut Éclat', 'whatsapp' => '077 12 34 56', 'color' => '#E8590C', 'booking_enabled' => true, 'is_active' => true, ...$extra];
}

it('creates cards up to the limit of the pack (3 on the free plan)', function () {
    foreach (range(1, 3) as $i) {
        $this->actingAs($this->admin)->post('/cards', cardPayload(['name' => "Collaborateur {$i}"]))->assertRedirect('/cards');
    }
    $this->actingAs($this->admin)->post('/cards', cardPayload())->assertSessionHas('error');

    expect(DigitalCard::where('organization_id', $this->organization->id)->count())->toBe(3)
        ->and(DigitalCard::first()->whatsapp_e164)->toBe('24177123456');

    $this->actingAs($this->admin)->get('/cards')->assertInertia(fn (Assert $page) => $page
        ->component('Cards/Index')->has('cards', 3)->where('limit', 3)->where('canCreate', false)->where('branding', true)->where('nextPack.label', 'Solo')->where('nextPack.cards', 10));

    // Équipe : 30 cartes, sans la mention.
    $this->organization->update(['plan' => 'equipe']);
    $this->admin->unsetRelation('organization');
    $this->actingAs($this->admin)->get('/cards')->assertInertia(fn (Assert $page) => $page->where('limit', 30)->where('canCreate', true)->where('branding', false));
});

it('shows the public card, counts visits, scans and clicks, and serves a vCard', function () {
    $card = DigitalCard::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'slug' => 'aicha-x1', 'name' => 'Aïcha Moussavou', 'whatsapp_e164' => '24177123456', 'phone_e164' => '24177123456']);

    $this->get('/c/aicha-x1')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Cards/Public')->where('card.name', 'Aïcha Moussavou')->where('branding', true));
    $this->get('/c/aicha-x1?src=qr')->assertOk();
    $this->postJson('/c/aicha-x1/clic', ['type' => 'whatsapp'])->assertOk();
    $vcard = $this->get('/c/aicha-x1/vcard')->assertOk()->assertHeader('Content-Type', 'text/vcard; charset=utf-8');

    expect($vcard->getContent())->toContain('FN:Aïcha Moussavou')->toContain('TEL;TYPE=CELL:+24177123456')
        ->and($card->events()->pluck('type')->sort()->values()->all())->toBe(['scan', 'vcard', 'view', 'whatsapp']);

    $card->update(['is_active' => false]);
    $this->get('/c/aicha-x1')->assertNotFound();
});

it('turns a contact left on the card into a customer with the card source and a task', function () {
    $owner = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $card = DigitalCard::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'user_id' => $owner->id, 'slug' => 'aicha-x2', 'name' => 'Aïcha']);

    $this->post('/c/aicha-x2/contact', ['name' => 'Jean Ndong', 'phone' => '066 00 11 22', 'message' => 'Un devis pour une coupe', 'consent' => true])->assertSessionHas('success');

    $customer = Customer::where('organization_id', $this->organization->id)->where('phone_e164', '24166001122')->firstOrFail();
    expect($customer->acquisition_source)->toBe('carte')
        ->and($customer->acquisition_card_id)->toBe($card->id)
        ->and($customer->owner_user_id)->toBe($owner->id)
        ->and($customer->contacts()->first()->consent_status)->toBe('opt_in');

    $task = Task::where('kind', 'card_contact')->firstOrFail();
    expect($task->customer_id)->toBe($customer->id)->and($task->details)->toBe('Un devis pour une coupe')->and($task->done_at)->toBeNull();

    // Anti-robot : champ caché rempli, rien n'est créé.
    $this->post('/c/aicha-x2/contact', ['name' => 'Robot', 'phone' => '077 99 99 99', 'site_web' => 'spam'])->assertSessionHas('success');
    expect(Customer::where('phone_e164', '24177999999')->exists())->toBeFalse();
});

it('creates and prefills the card after signing up from the website creator', function () {
    $this->get('/register?offre=carte&nom=Jean%20Ndong&poste=G%C3%A9rant&entreprise=Garage%20Ndong&whatsapp=066001122&couleur=%232B1A3D&instagram=%40garage.ndong')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('prefill.name', 'Jean Ndong')->where('prefill.organization_name', 'Garage Ndong'));

    $response = $this->post('/register', [
        'organization_name' => 'Garage Ndong', 'sector' => 'technique', 'store_name' => 'Garage Ndong', 'name' => 'Jean Ndong',
        'email' => 'jean@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
    ]);

    $card = DigitalCard::where('name', 'Jean Ndong')->firstOrFail();
    $response->assertRedirect("/cards/{$card->id}/edit");
    expect($card->job_title)->toBe('Gérant')->and($card->company)->toBe('Garage Ndong')
        ->and($card->whatsapp_e164)->toBe('24166001122')->and($card->color)->toBe('#2B1A3D')
        ->and($card->user->email)->toBe('jean@example.com')
        ->and($card->socials)->toBe(['instagram' => 'https://www.instagram.com/garage.ndong']);
});

it('keeps cards private to their organization', function () {
    $other = Organization::factory()->create();
    $card = DigitalCard::create(['organization_id' => $other->id, 'slug' => 'autre-x3', 'name' => 'Autre']);

    $this->actingAs($this->admin)->get("/cards/{$card->id}/edit")->assertNotFound();
    $this->actingAs($this->admin)->delete("/cards/{$card->id}")->assertNotFound();
});

it('limits the statistics period and printing to the plan', function () {
    $card = DigitalCard::create(['organization_id' => $this->organization->id, 'slug' => 'aicha-x4', 'name' => 'Aïcha']);

    // Gratuit : 7 jours au plus, pas d'impression.
    $this->actingAs($this->admin)->get('/cards?days=365')->assertInertia(fn (Assert $page) => $page
        ->where('days', 7)->where('canPrint', false)->where('printPack', 'Équipe')
        ->where('periods.1.allowed', false)->where('periods.1.pack', 'Solo')->where('periods.3.pack', 'Premium'));
    $this->actingAs($this->admin)->get("/cards/{$card->id}/print")->assertRedirect('/cards')->assertSessionHas('error');

    // Équipe : 90 jours et impression.
    $this->organization->update(['plan' => 'equipe']);
    $this->admin->unsetRelation('organization');
    $this->actingAs($this->admin)->get('/cards?days=365')->assertInertia(fn (Assert $page) => $page->where('days', 90)->where('canPrint', true));
    $this->actingAs($this->admin)->get("/cards/{$card->id}/print")->assertOk();
});

it('is listed in the modules, checked by default, and can be turned off without breaking shared cards', function () {
    DigitalCard::create(['organization_id' => $this->organization->id, 'slug' => 'aicha-x5', 'name' => 'Aïcha']);

    expect($this->organization->hasModule('carte_digitale'))->toBeTrue();
    $this->actingAs($this->admin)->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
        ->where('modules', fn ($modules) => collect($modules)->contains(fn ($m) => $m['key'] === 'carte_digitale' && $m['enabled'] && $m['suggested'])
            && collect($modules)->contains(fn ($m) => $m['key'] === 'fidelite')));

    $this->actingAs($this->admin)->put('/settings/modules', ['enabled' => ['rdv']])->assertSessionHasNoErrors();
    $this->organization->refresh();
    $this->admin->unsetRelation('organization');
    expect($this->organization->hasModule('carte_digitale'))->toBeFalse();
    $this->actingAs($this->admin)->get('/cards')->assertRedirect();
    $this->get('/c/aicha-x5')->assertOk();

    // Autre réglage des modules : la carte reste décochée.
    $this->organization->setModules(['rdv', 'catalogue']);
    $this->organization->save();
    expect($this->organization->fresh()->hasModule('carte_digitale'))->toBeFalse();
});

it('puts card contacts in the prospect pipeline with the card salesperson, but not existing clients', function () {
    $this->organization->setModules(['rdv', 'prospects']);
    $this->organization->save();
    $seller = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    DigitalCard::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'user_id' => $seller->id, 'slug' => 'vendeur-x6', 'name' => 'Paul Vendeur']);

    $this->post('/c/vendeur-x6/contact', ['name' => 'Nouveau Prospect', 'phone' => '066 11 22 33'])->assertSessionHas('success');
    $prospect = Customer::where('phone_e164', '24166112233')->firstOrFail();
    $pipeline = ProspectPipeline::for($this->organization->fresh());
    expect($prospect->prospect_stage)->toBe($pipeline->firstStage()['key'])
        ->and($prospect->owner_user_id)->toBe($seller->id)
        ->and($prospect->acquisition_source)->toBe('carte');

    $this->actingAs($this->admin)->get('/prospects?source=carte')->assertInertia(fn (Assert $page) => $page
        ->has('prospects', 1)->where('prospects.0.source', 'Carte digitale')->where('filters.source', 'carte'));

    // Client existant (déjà acheteur) : pas de prospect, une tâche « client existant ».
    $client = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177445566', 'name' => 'Cliente Fidèle']);
    $client->purchases()->create(['store_id' => $this->store->id, 'amount' => 15000, 'purchased_at' => now()->subDays(20)->toDateString()]);
    $this->post('/c/vendeur-x6/contact', ['name' => 'Cliente Fidèle', 'phone' => '077 44 55 66'])->assertSessionHas('success');
    expect($client->customer->fresh()->prospect_stage)->toBeNull()
        ->and(Task::where('customer_id', $client->customer_id)->value('title'))->toStartWith('Client existant');
});

it('saves social networks from links or @handles and shows them on the public card and vCard', function () {
    $this->actingAs($this->admin)->post('/cards', cardPayload(['socials' => ['instagram' => '@institut.eclat', 'facebook' => 'facebook.com/institut-eclat', 'link' => 'https://eclat.ga', 'x' => '']]))->assertRedirect('/cards');
    $card = DigitalCard::firstOrFail();

    expect($card->socials)->toBe(['facebook' => 'https://facebook.com/institut-eclat', 'instagram' => 'https://www.instagram.com/institut.eclat', 'link' => 'https://eclat.ga']);

    $this->get("/c/{$card->slug}")->assertInertia(fn (Assert $page) => $page->has('card.socials', 3)->where('card.socials.1.key', 'instagram'));
    $this->postJson("/c/{$card->slug}/clic", ['type' => 'social'])->assertOk();
    expect($card->events()->where('type', 'social')->count())->toBe(1)
        ->and($this->get("/c/{$card->slug}/vcard")->getContent())->toContain('X-SOCIALPROFILE;TYPE=instagram:https://www.instagram.com/institut.eclat');

    $this->actingAs($this->admin)->put("/cards/{$card->id}", cardPayload(['socials' => ['link' => 'pas un lien']]))->assertSessionHasErrors('socials.link');
});
