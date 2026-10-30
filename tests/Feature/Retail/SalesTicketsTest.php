<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['sector' => 'commerce']);
    Role::seedDefaultsFor($this->organization);
    $this->brandA = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne A']);
    $this->brandB = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Enseigne B']);
    $this->storeA = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandA->id, 'name' => 'A Centre']);
    $this->storeB = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brandB->id, 'name' => 'B Gare']);
    $this->admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Admin groupe')->value('id'),
    ]);

    $contact = Contact::factory()->create(['store_id' => $this->storeA->id, 'phone_e164' => '24162605054', 'name' => 'Awa']);
    $this->sale = Purchase::create(['contact_id' => $contact->id, 'store_id' => $this->storeA->id, 'amount' => 10000, 'purchased_at' => today()->subDays(3), 'receipt_number' => 'A-1', 'source_key' => 'r:A-1']);
    PurchaseItem::create(['purchase_id' => $this->sale->id, 'name' => 'Riz 5kg', 'quantity' => 1, 'unit_price' => 10000, 'amount' => 10000]);
    $this->anonymous = Purchase::create(['contact_id' => null, 'store_id' => $this->storeB->id, 'amount' => 3000, 'purchased_at' => today()->subDays(2), 'receipt_number' => 'B-7', 'source_key' => 'r:B-7', 'buyer_name' => 'Client de passage']);
    $this->return = Purchase::create(['contact_id' => $contact->id, 'store_id' => $this->storeA->id, 'amount' => -2500, 'purchased_at' => today()->subDay(), 'type' => Purchase::TYPE_RETURN, 'receipt_number' => 'A-R1', 'source_key' => 'r:A-R1', 'original_purchase_id' => $this->sale->id]);
});

it('lists tickets with net revenue, anonymous sales and returns', function () {
    $this->actingAs($this->admin)->get('/purchases?period=30d')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Purchases/Index')
            ->where('kpis.current.revenue', 10000 + 3000 - 2500)
            ->where('kpis.current.purchases', 2)
            ->where('kpis.current.returns', 2500)
            ->where('kpis.current.anonymous', 1)
            ->where('purchases.total', 3));

    $this->actingAs($this->admin)->get('/purchases?period=30d&anonymous=1')->assertOk()
        ->assertInertia(fn ($page) => $page->where('purchases.total', 1)->where('purchases.data.0.receipt_number', 'B-7')->where('purchases.data.0.contact.id', null));

    $this->actingAs($this->admin)->get('/purchases?period=30d&type=return')->assertOk()
        ->assertInertia(fn ($page) => $page->where('purchases.total', 1)->where('purchases.data.0.type', 'return'));
});

it('shows an anonymous ticket and a return linked to its original ticket', function () {
    $this->actingAs($this->admin)->get("/purchases/{$this->anonymous->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Purchases/Show')->where('ticket.customer', null)->where('ticket.buyer_name', 'Client de passage'));

    $this->actingAs($this->admin)->get("/purchases/{$this->sale->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('ticket.returns.0.receipt_number', 'A-R1')->where('ticket.lines.0.name', 'Riz 5kg'));
});

it('cancels a ticket with a reason, removes it from the figures and audits it', function () {
    $this->actingAs($this->admin)->post("/purchases/{$this->sale->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)->post("/purchases/{$this->sale->id}/cancel", ['reason' => 'Saisi deux fois'])->assertSessionHas('success');

    expect(Purchase::find($this->sale->id))->toBeNull()
        ->and(Purchase::withCancelled()->find($this->sale->id)->status)->toBe('cancelled')
        ->and($this->sale->contact->customer->fresh()->total_spent)->toBe(-2500)
        ->and(AuditLog::where('action', 'sale.cancelled')->sole()->description)->toContain('Saisi deux fois');

    $this->actingAs($this->admin)->get('/purchases?period=30d&status=cancelled')->assertOk()
        ->assertInertia(fn ($page) => $page->where('purchases.total', 1));

    $this->actingAs($this->admin)->post("/purchases/{$this->sale->id}/restore")->assertSessionHas('success');
    expect(Purchase::find($this->sale->id))->not->toBeNull();
});

it('keeps a brand manager out of the other brand tickets and exports', function () {
    $manager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Responsable de marque')->value('id'),
        'brand_id' => $this->brandA->id,
    ]);

    $this->actingAs($manager)->get("/purchases/{$this->anonymous->id}")->assertForbidden();
    $this->actingAs($manager)->get('/purchases?period=30d&brand='.$this->brandB->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('purchases.total', 2));

    $csv = $this->actingAs($manager)->get('/purchases/export?period=30d')->assertOk()->streamedContent();
    expect($csv)->toContain('A-1')->not->toContain('B-7');
    expect(AuditLog::where('action', 'export')->where('user_id', $manager->id)->sole()->payload['rows'])->toBe(2);
});

it('exports with the same filters as the screen and refuses roles without export right', function () {
    $csv = $this->actingAs($this->admin)->get('/purchases/export?period=30d&anonymous=1')->assertOk()->streamedContent();
    expect($csv)->toContain('B-7')->toContain('Client de passage (non rattaché)')->not->toContain('A-1');

    $operator = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => Role::where('organization_id', $this->organization->id)->where('name', 'Opérateur d’import')->value('id'),
    ]);
    $this->actingAs($operator)->get('/purchases/export')->assertForbidden();
});
