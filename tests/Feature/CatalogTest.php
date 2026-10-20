<?php

use App\Models\Appointment;
use App\Models\Brand;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
    $this->organization = Organization::factory()->create(['whatsapp_api_enabled' => false]);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Salon Louis']);
    $this->other = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'name' => 'Salon Akanda']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
    $this->category = Category::create(['organization_id' => $this->organization->id, 'name' => 'Coiffure']);

    $this->coupe = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Coupe', 'price' => 8000, 'duration_minutes' => 30, 'category_id' => $this->category->id]);
    $this->tresses = CatalogItem::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Tresses', 'price' => 25000, 'duration_minutes' => 120]);
    $this->shampoing = CatalogItem::factory()->product()->create(['organization_id' => $this->organization->id, 'price' => 6000]);

    $this->contact = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177451288', 'name' => 'Aïcha Mba']);
});

it('manages services and products in one catalogue', function () {
    $this->actingAs($this->admin)->post('/catalog', [
        'type' => 'service', 'name' => 'Manucure', 'price' => 7000, 'duration_minutes' => 45, 'store_ids' => [$this->other->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post('/catalog', ['type' => 'service', 'name' => 'Sans durée', 'price' => 1000])
        ->assertSessionHasErrors('duration_minutes');

    $this->actingAs($this->admin)->post('/catalog', ['type' => 'product', 'name' => 'Huile', 'price' => 4000, 'duration_minutes' => 30, 'reference' => 'H-01'])
        ->assertSessionHasNoErrors();

    $manucure = CatalogItem::where('name', 'Manucure')->sole();
    $huile = CatalogItem::where('name', 'Huile')->sole();

    expect($manucure->stores->pluck('id')->all())->toBe([$this->other->id])
        ->and($huile->duration_minutes)->toBeNull()
        ->and($huile->reference)->toBe('H-01')
        ->and(CatalogItem::availableIn($this->store->id)->pluck('name')->all())->not->toContain('Manucure');

    $this->actingAs($this->admin)->post("/catalog/{$manucure->id}/toggle")->assertRedirect();
    expect($manucure->fresh()->active)->toBeFalse();

    $this->actingAs($this->admin)->get('/catalog')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Catalog/Index')->where('items', fn ($items) => count($items) === 5));
});

it('books services from the catalogue: label and duration follow', function () {
    $this->actingAs($this->admin)->post('/appointments', [
        'store_id' => $this->store->id,
        'customer_id' => $this->contact->customer_id,
        'starts_at' => '2026-10-21T10:00',
        'item_ids' => [$this->coupe->id, $this->tresses->id, $this->shampoing->id],
    ])->assertSessionHasNoErrors();

    $appointment = Appointment::with('items')->sole();

    // Le produit n'est pas une prestation : ignoré.
    expect($appointment->service)->toBe('Coupe + Tresses')
        ->and($appointment->duration_minutes)->toBe(150)
        ->and($appointment->items->pluck('price')->all())->toBe([8000, 25000]);

    // Le prix du catalogue change : le rendez-vous garde le sien.
    $this->coupe->update(['price' => 9000]);

    $this->actingAs($this->admin)->get('/appointments?view=week&date=2026-10-21')
        ->assertInertia(fn ($page) => $page
            ->where('appointments.0.price', 33000)
            ->where('appointments.0.items.0.name', 'Coupe')
            ->where('catalog', fn ($catalog) => count($catalog) === 3));
});

it('checks out an appointment into a sale with lines', function () {
    $appointment = Appointment::factory()->create(['store_id' => $this->store->id, 'customer_id' => $this->contact->customer_id, 'starts_at' => '2026-10-20 10:00']);

    $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/checkout", [
        'lines' => [
            ['catalog_item_id' => $this->coupe->id, 'name' => 'Coupe', 'quantity' => 1, 'unit_price' => 8000],
            ['catalog_item_id' => $this->shampoing->id, 'name' => 'Shampoing', 'quantity' => 2, 'unit_price' => 6000],
            ['name' => 'Pourboire', 'quantity' => 1, 'unit_price' => 1000],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $purchase = Purchase::with('items')->sole();
    $appointment->refresh();

    expect($purchase->amount)->toBe(21000)
        ->and($purchase->quantity)->toBe(4)
        ->and($purchase->contact_id)->toBe($this->contact->id)
        ->and($purchase->product_category)->toBe('Coiffure')
        ->and($purchase->purchased_at->toDateString())->toBe('2026-10-20')
        ->and($purchase->items->pluck('type')->all())->toBe(['service', 'product', null])
        ->and($appointment->status)->toBe('done')
        ->and($appointment->purchase_id)->toBe($purchase->id)
        ->and($this->contact->customer->fresh()->total_spent)->toBe(21000);

    $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/checkout", [
        'lines' => [['name' => 'Encore', 'quantity' => 1, 'unit_price' => 1]],
    ])->assertSessionHasErrors('lines');
    expect(Purchase::count())->toBe(1);

    $this->actingAs($this->admin)->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('catalogMix.services', 8000)
            ->where('catalogMix.products', 12000)
            ->where('catalogMix.other', 1000)
            ->where('catalogMix.visits', 1)
            ->where('catalogMix.average', 21000)
            ->where('catalogMix.top.0.name', 'Coupe'));
});

it('creates the store contact when the customer is checked out in another store', function () {
    $appointment = Appointment::factory()->create(['store_id' => $this->other->id, 'customer_id' => $this->contact->customer_id]);

    $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/checkout", [
        'lines' => [['catalog_item_id' => $this->tresses->id, 'name' => 'Tresses', 'quantity' => 1, 'unit_price' => 25000]],
    ])->assertSessionHasNoErrors();

    $contact = Contact::where('store_id', $this->other->id)->sole();

    expect($contact->customer_id)->toBe($this->contact->customer_id)
        ->and(Purchase::sole()->contact_id)->toBe($contact->id);
});

it('keeps the catalogue to managers and to their own company', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->store->id,
    ]);
    $stranger = User::factory()->create();

    $this->actingAs($manager)->get('/catalog')->assertForbidden();
    $this->actingAs($stranger)->put("/catalog/{$this->coupe->id}", ['type' => 'service', 'name' => 'X', 'price' => 1, 'duration_minutes' => 30])->assertForbidden();
});
