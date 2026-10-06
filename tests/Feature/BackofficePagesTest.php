<?php

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Group;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
});

it('filters campaigns by search, status and type', function () {
    $promo = Campaign::factory()->create(['name' => 'Soldes été', 'status' => 'completed', 'message_type' => 'template']);
    $news = Campaign::factory()->create(['name' => 'Nouvelle collection', 'status' => 'sending', 'message_type' => 'text']);
    $promo->stores()->attach($this->store->id);
    $news->stores()->attach($this->store->id);

    $this->actingAs($this->admin)->get('/campaigns?q=soldes')
        ->assertInertia(fn ($page) => $page->has('campaigns.data', 1)->where('campaigns.data.0.id', $promo->id)->where('filters.q', 'soldes'));

    $this->actingAs($this->admin)->get('/campaigns?status=sending')
        ->assertInertia(fn ($page) => $page->has('campaigns.data', 1)->where('campaigns.data.0.id', $news->id)->where('stats.total_campaigns', 1));

    $this->actingAs($this->admin)->get('/campaigns?type=text&status=bogus')
        ->assertInertia(fn ($page) => $page->has('campaigns.data', 1)->where('campaigns.data.0.message_type', 'text')->where('filters.status', null));
});

it('shows how many campaigns used each template', function () {
    MessageTemplate::factory()->create(['organization_id' => $this->organization->id, 'name' => 'promo_ete']);
    $campaign = Campaign::factory()->create(['template_name' => 'promo_ete', 'message_type' => 'template']);
    $campaign->stores()->attach($this->store->id);

    $this->actingAs($this->admin)->get('/templates')
        ->assertInertia(fn ($page) => $page->where('templates.0.name', 'promo_ete')->where('templates.0.campaigns_count', 1));
});

it('shows contacts, opt-in contacts and campaign usage of each group', function () {
    $group = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Invités soirée']);
    $group->contacts()->attach([
        Contact::factory()->create(['store_id' => $this->store->id, 'consent_status' => 'opt_in'])->id,
        Contact::factory()->create(['store_id' => $this->store->id, 'consent_status' => 'unknown'])->id,
    ]);
    $campaign = Campaign::factory()->create(['segment_filters' => ['group_ids' => [$group->id]]]);
    $campaign->stores()->attach($this->store->id);

    $this->actingAs($this->admin)->get('/groups')
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.contacts_count', 2)
            ->where('groups.0.opt_in_count', 1)
            ->where('groups.0.campaigns_count', 1)
            ->where('groups.0.last_used_at', $campaign->created_at->toDateString()));
});

it('searches customers by name or local phone number', function () {
    Contact::factory()->create(['store_id' => $this->store->id, 'name' => 'Aïcha Mba', 'phone_e164' => '24162605054']);
    Contact::factory()->create(['store_id' => $this->store->id, 'name' => 'Brice Ndong', 'phone_e164' => '24177112233']);

    $this->actingAs($this->admin)->get('/customers?q=Mba')
        ->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.name', 'Aïcha Mba'));

    $this->actingAs($this->admin)->get('/customers?'.http_build_query(['q' => '077 11']))
        ->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.name', 'Brice Ndong'));
});

it('adds selected customers to a group, lists the members and removes them', function () {
    $group = Group::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Invités soirée']);
    $otherStore = Store::factory()->create(['organization_id' => $this->organization->id]);
    $aicha = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24162605054']);
    $aichaElsewhere = Contact::factory()->create(['store_id' => $otherStore->id, 'phone_e164' => '24162605054']);
    $brice = Contact::factory()->create(['store_id' => $this->store->id]);

    $this->actingAs($this->admin)->post("/groups/{$group->id}/customers", ['customer_ids' => [$aicha->fresh()->customer_id]])
        ->assertSessionHas('success');

    // Tous les contacts de la personne rejoignent le groupe.
    expect($group->contacts()->pluck('contacts.id')->sort()->values()->all())->toBe(collect([$aicha->id, $aichaElsewhere->id])->sort()->values()->all());

    $this->actingAs($this->admin)->get("/customers?group={$group->id}")
        ->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('filters.group', $group->id)->where('filters.label', 'Invités soirée'));

    $this->actingAs($this->admin)->get('/groups')
        ->assertInertia(fn ($page) => $page->where('groups.0.customers_count', 1)->where('groups.0.contacts_count', 2));

    // « Tout le filtre » : ajoute aussi Brice, sans toucher aux membres existants.
    $this->actingAs($this->admin)->post("/groups/{$group->id}/customers", ['all' => 1, 'filters' => []]);
    expect($group->contacts()->count())->toBe(3);

    $this->actingAs($this->admin)->delete("/groups/{$group->id}/customers", ['customer_ids' => [$brice->fresh()->customer_id], 'filters' => ['group' => $group->id]])
        ->assertSessionHas('success');
    expect($group->contacts()->pluck('contacts.id')->contains($brice->id))->toBeFalse()
        ->and($group->contacts()->count())->toBe(2);
});

it('only groups customers within the role scope and organization', function () {
    $group = Group::factory()->create(['organization_id' => $this->organization->id]);
    $foreignGroup = Group::factory()->create();
    $otherStore = Store::factory()->create(['organization_id' => $this->organization->id]);
    $outside = Contact::factory()->create(['store_id' => $otherStore->id]);
    $storeManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->store->id,
    ]);

    $this->actingAs($storeManager)->post("/groups/{$group->id}/customers", ['customer_ids' => [$outside->fresh()->customer_id]])->assertStatus(403);
    $this->actingAs($this->admin)->post("/groups/{$foreignGroup->id}/customers", ['customer_ids' => [$outside->fresh()->customer_id]])->assertStatus(403);
    expect($group->contacts()->count())->toBe(0);
});

it('lets the customer page add and remove the person from groups', function () {
    $group = Group::factory()->create(['organization_id' => $this->organization->id]);
    $contact = Contact::factory()->create(['store_id' => $this->store->id]);
    $group->contacts()->attach($contact->id);

    $this->actingAs($this->admin)->get("/customers/{$contact->fresh()->customer_id}")
        ->assertInertia(fn ($page) => $page->where('memberOf', [$group->id])->has('groups', 1));
});
it('lists what needs attention, with unread messages awaiting the webhook', function () {
    $contact = Contact::factory()->create(['store_id' => $this->store->id, 'consent_status' => 'unknown']);
    $running = Campaign::factory()->create(['status' => 'sending']);
    $stuck = Campaign::factory()->create(['status' => 'sending']);
    $running->stores()->attach($this->store->id);
    $stuck->stores()->attach($this->store->id);
    DB::table('campaigns')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);
    CampaignMessage::factory()->create(['campaign_id' => $running->id, 'contact_id' => $contact->id, 'status' => 'failed']);

    $notifications = app(NotificationService::class)->forUser($this->admin);
    $byKey = collect($notifications['items'])->keyBy('key');

    expect($notifications['unread'])->toBe(['available' => false, 'count' => 0])
        ->and($byKey['stuck_campaigns']['count'])->toBe(1)
        ->and($byKey['running_campaigns']['count'])->toBe(1)
        ->and($byKey['failed_sends']['count'])->toBe(1)
        ->and($byKey['pending_welcome']['count'])->toBe(1)
        // Les campagnes en cours (info) ne comptent pas dans la cloche.
        ->and($notifications['total'])->toBe(3);
});

it('keeps notifications within the role scope', function () {
    $otherStore = Store::factory()->create(['organization_id' => $this->organization->id]);
    Contact::factory()->create(['store_id' => $otherStore->id, 'consent_status' => 'unknown']);
    $storeManager = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role_id' => defaultRole($this->organization, 'Responsable de boutique')->id,
        'store_id' => $this->store->id,
    ]);

    expect(collect(app(NotificationService::class)->forUser($storeManager)['items'])->pluck('key'))->not->toContain('pending_welcome')
        ->and(collect(app(NotificationService::class)->forUser($this->admin)['items'])->pluck('key'))->toContain('pending_welcome');
});

it('shares the notifications with every page', function () {
    $this->actingAs($this->admin)->get('/groups')
        ->assertInertia(fn ($page) => $page->where('notifications.unread.available', false)->has('notifications.items'));
});
