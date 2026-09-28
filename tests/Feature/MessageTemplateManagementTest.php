<?php

use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\User;

it('creates a template scoped to the user organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post('/templates', [
        'name' => 'vente_privee',
        'language' => 'fr',
        'category' => 'marketing',
        'description' => 'Annonce de vente privée VIP',
        'is_active' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $template = MessageTemplate::first();
    expect($template)->not->toBeNull();
    expect($template->organization_id)->toBe($organization->id);
    expect($template->category)->toBe('marketing');
});

it('rejects a duplicate name/language pair within the same organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'hello_world', 'language' => 'en_US']);

    $response = $this->actingAs($user)->post('/templates', [
        'name' => 'hello_world',
        'language' => 'en_US',
        'category' => 'utility',
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors('name');
    expect(MessageTemplate::count())->toBe(1);
});

it('refuses to update or delete a template belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignTemplate = MessageTemplate::factory()->create(['organization_id' => $otherOrganization->id]);

    $this->actingAs($user)->put("/templates/{$foreignTemplate->id}", [
        'name' => 'hack',
        'language' => 'fr',
        'category' => 'utility',
        'is_active' => true,
    ])->assertStatus(403);

    $this->actingAs($user)->delete("/templates/{$foreignTemplate->id}")->assertStatus(403);

    expect(MessageTemplate::find($foreignTemplate->id))->not->toBeNull();
});

it('only proposes active templates belonging to the organization when creating a campaign', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'active_one', 'is_active' => true]);
    MessageTemplate::factory()->create(['organization_id' => $organization->id, 'name' => 'inactive_one', 'is_active' => false]);

    $response = $this->actingAs($user)->get('/campaigns');

    $response->assertInertia(fn ($page) => $page
        ->has('templates', 1)
        ->where('templates.0.name', 'active_one'),
    );
});
