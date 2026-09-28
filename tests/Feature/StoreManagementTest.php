<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('updates a store\'s WhatsApp credentials without erasing the token on a blank resubmit', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_access_token' => 'old-token',
        'whatsapp_phone_number_id' => '1111111',
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->put("/stores/{$store->id}", [
        'whatsapp_phone_number_id' => '2222222',
        'whatsapp_access_token' => '',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $store->refresh();
    expect($store->whatsapp_phone_number_id)->toBe('2222222');
    expect($store->whatsapp_access_token)->toBe('old-token');
});

it('replaces the WhatsApp token when a new one is submitted', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_access_token' => 'old-token',
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->put("/stores/{$store->id}", [
        'whatsapp_phone_number_id' => $store->whatsapp_phone_number_id,
        'whatsapp_access_token' => 'brand-new-token',
    ]);

    expect($store->fresh()->whatsapp_access_token)->toBe('brand-new-token');
});

it('refuses to update a store belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->put("/stores/{$otherStore->id}", [
        'whatsapp_phone_number_id' => 'hack',
    ])->assertStatus(403);
});

it('reports a successful WhatsApp connection test', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'verified_name' => 'Adidas Libreville',
            'display_phone_number' => '+241 77 00 00 00',
            'quality_rating' => 'GREEN',
        ], 200),
    ]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_access_token' => 'valid-token',
        'whatsapp_phone_number_id' => '123456',
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post("/stores/{$store->id}/test-connection");

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(session('success'))->toContain('Adidas Libreville');
});

it('reports a failed WhatsApp connection test without leaking a stack trace', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401),
    ]);

    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->id,
        'whatsapp_access_token' => 'invalid-token',
        'whatsapp_phone_number_id' => '123456',
    ]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post("/stores/{$store->id}/test-connection");

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

it('refuses to test the connection of a store belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user)->post("/stores/{$otherStore->id}/test-connection")->assertStatus(403);
});
