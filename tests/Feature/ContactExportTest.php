<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('exports only the contacts matching the current filters, scoped to the user organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);

    $user = User::factory()->create(['organization_id' => $organization->id]);

    Contact::factory()->create(['store_id' => $store->id, 'name' => 'Marie Femme', 'gender' => 'F']);
    Contact::factory()->create(['store_id' => $store->id, 'name' => 'Paul Homme', 'gender' => 'H']);
    Contact::factory()->create(['store_id' => $otherStore->id, 'name' => 'Autre Organisation', 'gender' => 'F']);

    $response = $this->actingAs($user)->get('/contacts/export?gender=F');

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();

    expect($content)->toContain('Marie Femme');
    expect($content)->not->toContain('Paul Homme');
    expect($content)->not->toContain('Autre Organisation');
});
