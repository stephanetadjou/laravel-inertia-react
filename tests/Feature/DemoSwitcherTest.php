<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function demoOrg(string $slug, string $sector): User
{
    $organization = Organization::factory()->create(['slug' => $slug, 'sector' => $sector, 'whatsapp_api_enabled' => false]);
    $brand = Brand::factory()->create(['organization_id' => $organization->id]);
    Store::factory()->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);

    return User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
}

beforeEach(function () {
    $this->salon = demoOrg('demo-nentix-beaute', 'beaute');
    $this->loueur = demoOrg('demo-ogooue-auto', 'vehicules');
});

it('switches between demo companies from the top bar when enabled', function () {
    config(['services.demo_switcher' => true]);

    $this->actingAs($this->salon)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('demoSwitcher.current', 'demo-nentix-beaute')
        ->has('demoSwitcher.options', 2));

    $this->post('/demo/switch', ['slug' => 'demo-ogooue-auto'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->loueur);
});

it('is hidden and refused when disabled or for a real company', function () {
    config(['services.demo_switcher' => false]);

    $this->actingAs($this->salon)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('demoSwitcher', null));
    $this->post('/demo/switch', ['slug' => 'demo-ogooue-auto'])->assertNotFound();

    config(['services.demo_switcher' => true]);
    $real = demoOrg('vrai-salon', 'beaute');
    $this->actingAs($real)->post('/demo/switch', ['slug' => 'demo-ogooue-auto'])->assertNotFound();
    $this->assertAuthenticatedAs($real);
});
