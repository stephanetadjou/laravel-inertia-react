<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('creates a contact manually', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post('/contacts', [
        'store_id' => $store->id,
        'name' => 'Nouveau Client',
        'phone_e164' => '77123456',
        'gender' => 'H',
        'consent_status' => 'unknown',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $contact = Contact::where('store_id', $store->id)->first();
    expect($contact)->not->toBeNull();
    expect($contact->name)->toBe('Nouveau Client');
    expect($contact->phone_e164)->toBe('24177123456');
});

it('refuses to create a contact into a store belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post('/contacts', [
        'store_id' => $otherStore->id,
        'name' => 'Intrus',
        'phone_e164' => '70000000',
        'consent_status' => 'unknown',
    ]);

    $response->assertSessionHas('error');
    expect(Contact::count())->toBe(0);
});

it('refuses to create a duplicate contact in the same store', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    Contact::factory()->create(['store_id' => $store->id, 'phone_e164' => '24177123456']);

    $response = $this->actingAs($user)->post('/contacts', [
        'store_id' => $store->id,
        'name' => 'Doublon',
        'phone_e164' => '77123456',
        'consent_status' => 'unknown',
    ]);

    $response->assertSessionHas('error');
    expect(Contact::where('store_id', $store->id)->count())->toBe(1);
});

it('redirects the old contacts list to the customer list, keeping its filters', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->get('/contacts?group_id=4&consent_status=opt_in&q=Mba')
        ->assertRedirect('/customers?q=Mba&consent=opt_in&group=4');
});

it('updates a contact profile', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id, 'name' => 'Amanda Obame', 'gender' => 'F']);

    $response = $this->actingAs($user)->put("/contacts/{$contact->id}", [
        'name' => 'Amanda Ella',
        'phone_e164' => $contact->phone_e164,
        'gender' => 'F',
        'birthdate' => '1990-05-12',
        'consent_status' => 'opt_in',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect($contact->fresh()->name)->toBe('Amanda Ella');
    expect($contact->fresh()->consent_status)->toBe('opt_in');
    expect($contact->fresh()->birthdate->format('Y-m-d'))->toBe('1990-05-12');
});

it('refuses to update a contact belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);

    $this->actingAs($user)->put("/contacts/{$foreignContact->id}", [
        'name' => 'Hacked Name',
        'phone_e164' => $foreignContact->phone_e164,
        'consent_status' => 'opt_in',
    ])->assertStatus(403);
});

it('deletes a contact and its related records', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);

    $response = $this->actingAs($user)->delete("/contacts/{$contact->id}");

    // Dernier contact de la personne : la fiche disparaît, retour à la liste.
    $response->assertRedirect(route('customers.index'));
    $response->assertSessionHas('success');
    expect(Contact::find($contact->id))->toBeNull();
});

it('goes back to the customer after deleting one of its store contacts', function () {
    $organization = Organization::factory()->create();
    $adidas = Store::factory()->create(['organization_id' => $organization->id]);
    $mango = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $kept = Contact::factory()->create(['store_id' => $adidas->id, 'phone_e164' => '24177451288']);
    $removed = Contact::factory()->create(['store_id' => $mango->id, 'phone_e164' => '24177451288']);

    $this->actingAs($user)->delete("/contacts/{$removed->id}")
        ->assertRedirect(route('customers.show', $kept->customer_id));
});

it('refuses to delete a contact belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);

    $this->actingAs($user)->delete("/contacts/{$foreignContact->id}")->assertStatus(403);

    expect(Contact::find($foreignContact->id))->not->toBeNull();
});

it('refuses to create a contact with a number that is not a Gabonese mobile', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post('/contacts', [
        'store_id' => $store->id,
        'name' => 'Neuf Chiffres',
        'phone_e164' => '612345678',
        'consent_status' => 'unknown',
    ]);

    $response->assertSessionHas('error');
    expect(Contact::count())->toBe(0);
});
