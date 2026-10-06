<?php

use App\Jobs\SendCampaignJob;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));

    $this->organization = Organization::factory()->create();
    $this->adidasBrand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->mangoBrand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
    $this->adidas = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidasBrand->id, 'name' => 'Adidas Glass']);
    $this->mango = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->mangoBrand->id, 'name' => 'Mango Mbolo']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
    MessageTemplate::create(['organization_id' => $this->organization->id, 'name' => 'promo', 'language' => 'fr', 'category' => 'marketing', 'is_active' => true]);

    $buy = fn (Contact $c, int $amount, int $daysAgo) => Purchase::factory()->create([
        'contact_id' => $c->id, 'store_id' => $c->store_id, 'amount' => $amount, 'purchased_at' => now()->subDays($daysAgo),
    ]);

    // Aïcha : nouvelle cliente Adidas (opt-in) et grosse cliente Mango (VIP, opt-in).
    $this->aichaAdidas = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha', 'consent_status' => 'opt_in']);
    $this->aichaMango = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha', 'consent_status' => 'opt_in']);
    $buy($this->aichaAdidas, 10000, 10);
    $buy($this->aichaMango, 500000, 20);

    // Brice : nouveau client Adidas, consentement inconnu.
    $this->brice = Contact::factory()->create(['store_id' => $this->adidas->id, 'phone_e164' => '24166210347', 'name' => 'Brice', 'consent_status' => 'unknown']);
    $buy($this->brice, 20000, 5);

    // Carine : cliente Mango inactive (opt-out).
    $this->carine = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24162378105', 'name' => 'Carine', 'consent_status' => 'opt_out']);
    $buy($this->carine, 30000, 400);

    // Denis : petit client Mango récent, opt-in.
    $this->denis = Contact::factory()->create(['store_id' => $this->mango->id, 'phone_e164' => '24174092357', 'name' => 'Denis', 'consent_status' => 'opt_in']);
    $buy($this->denis, 5000, 3);
});

it('combines stage, brand, VIP and consent filters', function () {
    $ids = fn (string $query) => collect($this->actingAs($this->admin)->get('/customers?'.$query)->viewData('page')['props']['customers']['data'])
        ->pluck('name')->sort()->values()->all();

    // Nouveaux clients d'Adidas.
    expect($ids("stage=new&brand={$this->adidasBrand->id}"))->toBe(['Aïcha', 'Brice']);
    // Clients VIP de Mango : Aïcha. Chez Adidas, le meilleur acheteur est Brice.
    expect($ids("vip=1&brand={$this->mangoBrand->id}"))->toBe(['Aïcha']);
    expect($ids("vip=1&brand={$this->adidasBrand->id}"))->toBe(['Brice']);
    // Inactifs de Mango.
    expect($ids("stage=churned&store={$this->mango->id}"))->toBe(['Carine']);
    // Consentement.
    expect($ids('consent=opt_in'))->toBe(['Aïcha', 'Denis']);
    expect($ids('consent=unknown'))->toBe(['Brice']);
    expect($ids("consent=opt_in&brand={$this->adidasBrand->id}"))->toBe(['Aïcha']);

    $this->actingAs($this->admin)->get("/customers?brand={$this->adidasBrand->id}")
        ->assertInertia(fn ($page) => $page
            ->where('consentCounts.opt_in', 1)
            ->where('consentCounts.unknown', 1)
            ->where('consentCounts.opt_out', 0)
            ->where('reachableCount', 1)
            ->where('filters.label', 'Adidas')
            ->has('templates', 1));
});

it('previews the audience of a selection: only opt-in contacts of the filtered stores', function () {
    $aichaId = $this->aichaAdidas->customer_id;
    $briceId = $this->brice->customer_id;

    $this->actingAs($this->admin)
        ->getJson('/customers/audience?'.http_build_query(['brand' => $this->adidasBrand->id, 'customer_ids' => [$aichaId, $briceId]]))
        ->assertOk()
        ->assertJson(['customers' => 2, 'reachable_customers' => 1, 'recipients' => 1, 'stores' => ['Adidas Glass']]);

    // Sans filtre de marque : Aïcha est joignable par ses deux boutiques.
    $this->actingAs($this->admin)
        ->getJson('/customers/audience?'.http_build_query(['all' => 1]))
        ->assertJson(['customers' => 4, 'reachable_customers' => 2, 'recipients' => 3, 'stores' => ['Adidas Glass', 'Mango Mbolo']]);
});

it('launches a campaign from selected customers, restricted to the filter and to opt-in', function () {
    $this->actingAs($this->admin)->post('/campaigns', [
        'name' => 'VIP Mango',
        'contact_source' => 'customers',
        'customer_ids' => [$this->aichaAdidas->customer_id],
        'filters' => ['vip' => '1', 'brand' => $this->mangoBrand->id],
        'message_type' => 'template',
        'template_name' => 'promo',
    ])->assertRedirect(route('campaigns.index'))->assertSessionHas('success');

    $campaign = Campaign::sole();
    // Seul le contact Mango (VIP) d'Aïcha reçoit le message, depuis Mango.
    expect($campaign->campaignMessages()->pluck('contact_id')->all())->toBe([$this->aichaMango->id]);
    expect($campaign->stores()->pluck('stores.id')->all())->toBe([$this->mango->id]);
    expect($campaign->segment_filters['source'])->toBe('customers');
    expect($campaign->segment_filters['label'])->toBe('VIP · Mango');
    Queue::assertPushed(SendCampaignJob::class);
});

it('sends to every opt-in customer of the filter with all_customers', function () {
    $this->actingAs($this->admin)->post('/campaigns', [
        'name' => 'Mango',
        'contact_source' => 'customers',
        'all_customers' => 1,
        'filters' => ['store' => $this->mango->id],
        'message_type' => 'template',
        'template_name' => 'promo',
    ])->assertSessionHas('success');

    // Aïcha et Denis (opt-in) ; Carine (opt-out) exclue.
    expect(Campaign::sole()->campaignMessages()->pluck('contact_id')->sort()->values()->all())
        ->toBe(collect([$this->aichaMango->id, $this->denis->id])->sort()->values()->all());
});

it('refuses a campaign when no selected customer has given consent', function () {
    $this->actingAs($this->admin)->post('/campaigns', [
        'name' => 'Test',
        'contact_source' => 'customers',
        'customer_ids' => [$this->brice->customer_id, $this->carine->customer_id],
        'message_type' => 'template',
        'template_name' => 'promo',
    ])->assertSessionHas('error');

    expect(Campaign::count())->toBe(0);
});

it('keeps a brand manager within their brand even with foreign customer ids', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de marque')->id,
        'brand_id' => $this->adidasBrand->id,
    ]);

    $this->actingAs($manager)->post('/campaigns', [
        'name' => 'Tentative',
        'contact_source' => 'customers',
        'customer_ids' => [$this->aichaAdidas->customer_id, $this->denis->customer_id],
        'filters' => ['brand' => $this->mangoBrand->id],
        'message_type' => 'template',
        'template_name' => 'promo',
    ])->assertSessionHas('success');

    // Seul le contact Adidas d'Aïcha : Denis (Mango) est hors périmètre.
    expect(Campaign::sole()->campaignMessages()->pluck('contact_id')->all())->toBe([$this->aichaAdidas->id]);
});

it('gives each row what the edit window needs', function () {
    $this->actingAs($this->admin)->get('/customers?consent=unknown')
        ->assertInertia(fn ($page) => $page
            ->where('customers.data.0.name', 'Brice')
            ->where('customers.data.0.can_edit', true)
            ->has('customers.data.0.contacts', 1)
            ->where('customers.data.0.contacts.0.id', $this->brice->id)
            ->where('customers.data.0.contacts.0.editable', true));

    // Même filtré sur Mango, « Modifier » propose aussi la boutique Adidas d'Aïcha.
    $this->actingAs($this->admin)->get("/customers?vip=1&brand={$this->mangoBrand->id}")
        ->assertInertia(fn ($page) => $page->has('customers.data.0.contacts', 2));
});

it('updates the WhatsApp consent of each store from the customer edit window', function () {
    $this->actingAs($this->admin)->put("/customers/{$this->brice->customer_id}", [
        'name' => 'Brice Obame',
        'phone_e164' => $this->brice->phone_e164,
        'consents' => [$this->brice->id => 'opt_in'],
    ])->assertSessionHas('success');

    expect($this->brice->fresh()->consent_status)->toBe('opt_in');
    expect($this->brice->customer->fresh()->name)->toBe('Brice Obame');

    $this->actingAs($this->admin)->put("/customers/{$this->brice->customer_id}", [
        'name' => 'Brice',
        'phone_e164' => $this->brice->phone_e164,
        'consents' => [$this->brice->id => 'peut-être'],
    ])->assertSessionHasErrors('consents.'.$this->brice->id);
});

it('refuses to change the consent of a store outside the user scope', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->adidas->id,
    ]);

    // Aïcha : son contact Mango n'est pas dans le périmètre d'un responsable Adidas.
    $this->actingAs($manager)->put("/customers/{$this->aichaAdidas->customer_id}", [
        'name' => 'Aïcha',
        'phone_e164' => $this->aichaAdidas->phone_e164,
        'consents' => [$this->aichaMango->id => 'opt_out'],
    ])->assertStatus(403);

    expect($this->aichaMango->fresh()->consent_status)->toBe('opt_in');
});
