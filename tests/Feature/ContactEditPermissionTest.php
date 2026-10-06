<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;

function contactPayload(Contact $contact, array $overrides = []): array
{
    return array_merge([
        'name' => $contact->name,
        'phone_e164' => $contact->phone_e164,
        'gender' => $contact->gender,
        'birthdate' => null,
        'consent_status' => $contact->consent_status,
    ], $overrides);
}

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->adidas = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->mango = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Mango']);
    $this->adidasStore = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidas->id]);
    $this->adidasStore2 = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->adidas->id]);
    $this->mangoStore = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->mango->id]);
    $this->contact = Contact::factory()->create([
        'store_id' => $this->adidasStore->id,
        'name' => 'Amanda Obame',
        'phone_e164' => '24177123456',
        'consent_status' => 'unknown',
    ]);
});

it('lets an administrator edit any contact of the organization', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Administrateur')->id]);

    $this->actingAs($admin)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['name' => 'Amanda Ella']))
        ->assertSessionHas('success');

    expect($this->contact->fresh()->name)->toBe('Amanda Ella');
});

it('lets the brand manager edit a contact of any store of their brand', function () {
    $contactInOtherAdidasStore = Contact::factory()->create(['store_id' => $this->adidasStore2->id, 'phone_e164' => '24166000000']);
    $brandManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de marque')->id,
        'brand_id' => $this->adidas->id,
    ]);

    $this->actingAs($brandManager)
        ->put("/contacts/{$contactInOtherAdidasStore->id}", contactPayload($contactInOtherAdidasStore, ['consent_status' => 'opt_in']))
        ->assertSessionHas('success');

    expect($contactInOtherAdidasStore->fresh()->consent_status)->toBe('opt_in');
});

it('refuses the brand manager of another brand', function () {
    $mangoManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de marque')->id,
        'brand_id' => $this->mango->id,
    ]);

    $this->actingAs($mangoManager)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['name' => 'Piraté']))
        ->assertForbidden();

    expect($this->contact->fresh()->name)->toBe('Amanda Obame');
});

it('lets the store manager edit a contact of their own store only', function () {
    $storeManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->adidasStore->id,
    ]);
    $contactInSisterStore = Contact::factory()->create(['store_id' => $this->adidasStore2->id]);

    $this->actingAs($storeManager)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['name' => 'Amanda Ella']))
        ->assertSessionHas('success');

    $this->actingAs($storeManager)
        ->put("/contacts/{$contactInSisterStore->id}", contactPayload($contactInSisterStore, ['name' => 'Piraté']))
        ->assertForbidden();

    expect($this->contact->fresh()->name)->toBe('Amanda Ella');
    expect($contactInSisterStore->fresh()->name)->not->toBe('Piraté');
});

it('refuses an organization-wide role without administration rights, and a user without role', function () {
    $agentRole = Role::factory()->create([
        'organization_id' => $this->organization->id,
        'scope' => Role::SCOPE_ORGANIZATION,
        'can_manage_organization' => false,
    ]);
    $agent = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $agentRole->id]);
    $noRole = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => null]);

    $this->actingAs($agent)->put("/contacts/{$this->contact->id}", contactPayload($this->contact))->assertForbidden();
    $this->actingAs($noRole)->put("/contacts/{$this->contact->id}", contactPayload($this->contact))->assertForbidden();
});

it('refuses an administrator of another organization', function () {
    $otherOrganization = Organization::factory()->create();
    $foreignAdmin = User::factory()->create(['organization_id' => $otherOrganization->id, 'role_id' => defaultRole($otherOrganization, 'Administrateur')->id]);

    $this->actingAs($foreignAdmin)->put("/contacts/{$this->contact->id}", contactPayload($this->contact))->assertForbidden();
});

it('normalizes the Gabonese phone number when editing a contact', function (string $input) {
    $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Administrateur')->id]);

    $this->actingAs($admin)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['phone_e164' => $input]))
        ->assertSessionHas('success');

    expect($this->contact->fresh()->phone_e164)->toBe('24162605054');
})->with(['62 60 50 54', '062605054', '+241 62 60 50 54', '00241-62-60-50-54']);

it('rejects an invalid phone number without touching the contact', function () {
    $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Administrateur')->id]);

    $this->actingAs($admin)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['name' => 'Nouveau nom', 'phone_e164' => '612345678']))
        ->assertSessionHasErrors('phone_e164');

    expect($this->contact->fresh()->phone_e164)->toBe('24177123456');
    expect($this->contact->fresh()->name)->toBe('Amanda Obame');
});

it('rejects a number already used by another contact of the same store, whatever its input format', function () {
    Contact::factory()->create(['store_id' => $this->adidasStore->id, 'phone_e164' => '24162605054']);
    $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Administrateur')->id]);

    $this->actingAs($admin)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['phone_e164' => '062 60 50 54']))
        ->assertSessionHasErrors('phone_e164');

    expect($this->contact->fresh()->phone_e164)->toBe('24177123456');
});

it('allows the same number in a different store', function () {
    Contact::factory()->create(['store_id' => $this->mangoStore->id, 'phone_e164' => '24162605054']);
    $admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Administrateur')->id]);

    $this->actingAs($admin)
        ->put("/contacts/{$this->contact->id}", contactPayload($this->contact, ['phone_e164' => '62605054']))
        ->assertSessionHas('success');

    expect($this->contact->fresh()->phone_e164)->toBe('24162605054');
});

it('tells the customer page whether the current user may edit', function () {
    $storeManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->adidasStore->id,
    ]);
    $agentRole = Role::factory()->create(['organization_id' => $this->organization->id, 'scope' => Role::SCOPE_ORGANIZATION]);
    $agent = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => $agentRole->id]);

    $this->actingAs($storeManager)->get("/customers/{$this->contact->customer_id}")
        ->assertInertia(fn ($page) => $page->where('canEdit', true));

    $this->actingAs($agent)->get("/customers/{$this->contact->customer_id}")
        ->assertInertia(fn ($page) => $page->where('canEdit', false));
});
