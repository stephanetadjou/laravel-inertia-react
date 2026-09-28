<?php

use App\Models\Category;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;

it('creates a category', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);

    $response = $this->actingAs($user)->post('/categories', ['name' => 'Chaussures']);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Category::where('organization_id', $organization->id)->where('name', 'Chaussures')->exists())->toBeTrue();
});

it('refuses a duplicate category name within the same organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Chaussures']);

    $this->actingAs($user)->post('/categories', ['name' => 'Chaussures'])->assertSessionHasErrors('name');
});

it('renames a category', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $category = Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Chaussure']);

    $response = $this->actingAs($user)->put("/categories/{$category->id}", ['name' => 'Chaussures']);

    $response->assertRedirect();
    expect($category->fresh()->name)->toBe('Chaussures');
});

it('deletes a category without affecting recorded purchases', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);
    $category = Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Chaussures']);
    $purchase = Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'product_category' => 'Chaussures']);

    $response = $this->actingAs($user)->delete("/categories/{$category->id}");

    $response->assertSessionHas('success');
    expect(Category::find($category->id))->toBeNull();
    expect($purchase->fresh()->product_category)->toBe('Chaussures');
});

it('refuses to manage a category belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $foreignCategory = Category::factory()->create(['organization_id' => $otherOrganization->id]);

    $this->actingAs($user)->put("/categories/{$foreignCategory->id}", ['name' => 'Hack'])->assertStatus(403);
    $this->actingAs($user)->delete("/categories/{$foreignCategory->id}")->assertStatus(403);
});

it('lists categories with their purchase usage count, scoped to the organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id, 'role_id' => defaultRole($organization, 'Super administrateur')->id]);
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);
    Category::factory()->create(['organization_id' => $organization->id, 'name' => 'Chaussures']);
    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'product_category' => 'Chaussures']);
    Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'product_category' => 'Chaussures']);

    $response = $this->actingAs($user)->get('/categories');

    $response->assertInertia(fn ($page) => $page
        ->has('categories', 1)
        ->where('categories.0.name', 'Chaussures')
        ->where('categories.0.purchases_count', 2),
    );
});
