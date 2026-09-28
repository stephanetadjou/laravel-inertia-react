<?php

use App\Models\Contact;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

it('creates a group scoped to the user organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $response = $this->actingAs($user)->post('/groups', ['name' => 'VIP']);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Group::where('organization_id', $organization->id)->where('name', 'VIP')->exists())->toBeTrue();
});

it('refuses two groups with the same name in the same organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    Group::factory()->create(['organization_id' => $organization->id, 'name' => 'VIP']);

    $response = $this->actingAs($user)->post('/groups', ['name' => 'VIP']);

    $response->assertSessionHasErrors('name');
    expect(Group::where('organization_id', $organization->id)->count())->toBe(1);
});

it('allows the same group name in two different organizations', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $userB = User::factory()->create(['organization_id' => $organizationB->id]);
    Group::factory()->create(['organization_id' => $organizationA->id, 'name' => 'VIP']);

    $response = $this->actingAs($userB)->post('/groups', ['name' => 'VIP']);

    $response->assertSessionDoesntHaveErrors();
    expect(Group::where('organization_id', $organizationB->id)->where('name', 'VIP')->exists())->toBeTrue();
});

it('renames a group', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $group = Group::factory()->create(['organization_id' => $organization->id, 'name' => 'Ancien nom']);

    $response = $this->actingAs($user)->put("/groups/{$group->id}", ['name' => 'Nouveau nom']);

    $response->assertRedirect();
    expect($group->fresh()->name)->toBe('Nouveau nom');
});

it('deletes a group without deleting its contacts', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $group = Group::factory()->create(['organization_id' => $organization->id]);
    $contact = Contact::factory()->create(['store_id' => $store->id]);
    $group->contacts()->attach($contact->id);

    $response = $this->actingAs($user)->delete("/groups/{$group->id}");

    $response->assertRedirect();
    expect(Group::find($group->id))->toBeNull();
    expect(Contact::find($contact->id))->not->toBeNull();
});

it('refuses to update or delete a group belonging to another organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $foreignGroup = Group::factory()->create(['organization_id' => $otherOrganization->id]);

    $this->actingAs($user)->put("/groups/{$foreignGroup->id}", ['name' => 'Hack'])->assertStatus(403);
    $this->actingAs($user)->delete("/groups/{$foreignGroup->id}")->assertStatus(403);
});

it('bulk assigns selected contacts to a group', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $group = Group::factory()->create(['organization_id' => $organization->id]);
    $contacts = Contact::factory()->count(3)->create(['store_id' => $store->id]);

    $response = $this->actingAs($user)->post('/contacts/assign-group', [
        'group_id' => $group->id,
        'contact_ids' => $contacts->pluck('id')->all(),
    ]);

    $response->assertRedirect();
    expect($group->contacts()->count())->toBe(3);
});

it('ignores contacts belonging to another organization when bulk assigning a group', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $otherOrganization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $group = Group::factory()->create(['organization_id' => $organization->id]);
    $ownContact = Contact::factory()->create(['store_id' => $store->id]);
    $foreignContact = Contact::factory()->create(['store_id' => $otherStore->id]);

    $this->actingAs($user)->post('/contacts/assign-group', [
        'group_id' => $group->id,
        'contact_ids' => [$ownContact->id, $foreignContact->id],
    ]);

    expect($group->contacts()->pluck('contacts.id')->all())->toBe([$ownContact->id]);
});
