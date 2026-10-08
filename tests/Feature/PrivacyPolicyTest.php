<?php

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('gives every organization a unique slug', function () {
    $first = Organization::factory()->create(['name' => 'Boutique Akanda']);
    $second = Organization::factory()->create(['name' => 'Boutique Akanda']);

    expect($first->slug)->toBe('boutique-akanda')
        ->and($second->slug)->toBe('boutique-akanda-2');
});

it('keeps the policy URL when the organization is renamed', function () {
    $organization = Organization::factory()->create(['name' => 'Ancien nom']);
    $url = $organization->privacyPolicyUrl();

    $organization->update(['name' => 'Nouveau nom']);

    expect($organization->fresh()->privacyPolicyUrl())->toBe($url);
});

it('serves a public privacy policy rendered on the server', function () {
    $organization = Organization::factory()->create([
        'name' => 'Retail Group',
        'legal_name' => 'Retail Group SA',
        'privacy_email' => 'dpo@retail.example',
        'privacy_address' => 'Boulevard Triomphal, Libreville',
        'privacy_extra' => 'Clause <b>spéciale</b>',
    ]);
    Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Mango Glass']);

    $this->get("/confidentialite/{$organization->slug}")
        ->assertOk()
        ->assertSee('Politique de confidentialité')
        ->assertSee('Retail Group SA')
        ->assertSee('dpo@retail.example')
        ->assertSee('Mango Glass')
        ->assertSee('STOP')
        ->assertSee('Clause &lt;b&gt;spéciale&lt;/b&gt;', false);
});

it('returns 404 for an unknown company', function () {
    $this->get('/confidentialite/inconnue')->assertNotFound();
});

it('lets administrators fill in the policy contact details', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);

    $this->actingAs($admin)->get('/organisation')
        ->assertInertia(fn ($page) => $page->where('privacy.url', $organization->privacyPolicyUrl()));

    $this->actingAs($admin)->put('/organisation/privacy', ['email' => 'contact@akanda.example', 'phone' => '+241 77 00 00 00'])
        ->assertSessionHas('success');

    expect($organization->fresh()->privacy_email)->toBe('contact@akanda.example');
});

it('requires a contact email and refuses non administrators', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Administrateur')->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $manager = User::factory()->create([
        'organization_id' => $organization->id,
        'role_id' => defaultRole($organization, 'Responsable de boutique')->id,
        'store_id' => $store->id,
    ]);

    $this->actingAs($admin)->put('/organisation/privacy', ['email' => ''])->assertSessionHasErrors('email');
    $this->actingAs($manager)->put('/organisation/privacy', ['email' => 'x@y.z'])->assertStatus(403);
});

it('creates the policy page with the company at registration', function () {
    $this->post('/register', [
        'organization_name' => 'Boutique Owendo',
        'store_name' => 'Owendo Centre',
        'name' => 'Paul',
        'email' => 'paul@example.com',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
    ]);

    $this->get('/confidentialite/boutique-owendo')->assertOk()->assertSee('Owendo Centre');
});

it('gives a registered company a free slug when its name is already taken', function () {
    Organization::factory()->create(['name' => 'Retail Group']);

    $this->post('/register', [
        'organization_name' => 'RETAIL GROUP',
        'store_name' => 'MOODA',
        'name' => 'MOODA',
        'email' => 'mooda@example.com',
        'password' => 'Secret-123456',
        'password_confirmation' => 'Secret-123456',
    ])->assertRedirect(route('dashboard.index'));

    expect(Organization::where('name', 'RETAIL GROUP')->value('slug'))->toBe('retail-group-2');
    $this->get('/confidentialite/retail-group-2')->assertOk()->assertSee('MOODA');
});
