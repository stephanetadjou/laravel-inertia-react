<?php

use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Modules\Depenses\Models\Expense;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Services\LocationFollowUps;
use App\Support\OnlinePage;
use App\Support\SectorPresets;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-16 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'location', 'whatsapp_api_enabled' => false]);
    SectorPresets::apply($this->organization, true);
    $this->organization->setModules([...$this->organization->enabledModules(), 'depenses']);
    $this->organization->save();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id, 'booking_phone' => '24166001122']);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->car = BookableResource::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Toyota RAV4', 'kind' => 'vehicule',
        'category' => 'suv', 'active' => true, 'status' => 'available', 'price_day' => 40000, 'mileage' => 10000]);
});

it('shows « Que souhaitez-vous ? » on the public link, with the location choices', function () {
    $this->get("/rdv/{$this->organization->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('OnlinePage/Show')
        ->where('choices.0.key', 'ride')->has('categories', 1));
    expect(OnlinePage::enabledChoices($this->organization))->toContain('rent', 'event', 'buy', 'callback')->not->toContain('rdv');

    $this->getJson("/rdv/{$this->organization->slug}/disponibles?from=2026-11-20T08:00&to=2026-11-22T08:00")->assertOk()->assertJsonPath('days', 2)->assertJsonPath('vehicles.0.estimate', 80000);

    // Les autres secteurs gardent la prise de rendez-vous directe tant qu'ils n'activent pas la page.
    $salon = Organization::factory()->create(['sector' => 'beaute']);
    expect(OnlinePage::isEnabled($salon))->toBeFalse()->and(OnlinePage::enabledChoices($salon))->toBe(['rdv', 'prices', 'callback']);
});

it('creates the client, the request and a task from the public link, with anti-abuse checks', function () {
    $url = "/rdv/{$this->organization->slug}/demande";
    $payload = ['type' => 'rent', 'name' => 'Grâce Mba', 'phone' => '077123456', 'starts_at' => '2026-11-20 08:00', 'ends_at' => '2026-11-22 08:00', 'resource_id' => $this->car->id, 'with_driver' => true];

    $this->post($url, [...$payload, 'phone' => '12'])->assertSessionHasErrors('phone');
    $this->post($url, [...$payload, 'website' => 'robot'])->assertSessionHasErrors('website');
    $this->post($url, [...$payload, 'type' => 'trial'])->assertSessionHasErrors('type');
    $this->post($url, $payload)->assertRedirect("/rdv/{$this->organization->slug}")->assertSessionHas('online_sent');

    $request = VehicleRequest::firstOrFail();
    expect($request->source)->toBe('online')->and($request->with_driver)->toBeTrue()->and($request->notes)->toContain('étape Commande')
        ->and($request->customer->acquisition_source)->toBe('lien')
        ->and(Task::where('kind', 'online_request')->where('customer_id', $request->customer_id)->exists())->toBeTrue();

    foreach (range(1, 4) as $i) {
        $this->post($url, [...$payload, 'phone' => '07712345'.$i]);
    }
    $this->post($url, $payload)->assertStatus(429);

    $this->travel(2)->hours();
    expect(collect(LocationFollowUps::due($this->organization, [$this->store->id]))->pluck('kind')->all())->toContain('online_new');
});

it('credits the collaborator of a digital card with ?carte=', function () {
    $seller = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $card = DigitalCard::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'user_id' => $seller->id, 'slug' => 'jean-mballa', 'name' => 'Jean Mballa', 'is_active' => true]);

    $this->get("/rdv/{$this->organization->slug}?carte=jean-mballa")->assertInertia(fn (Assert $page) => $page->where('card.name', 'Jean Mballa'));
    $this->post("/rdv/{$this->organization->slug}/demande", ['type' => 'event', 'name' => 'Paul Obame', 'phone' => '077998877', 'starts_at' => '2026-12-06 09:00', 'vehicles_count' => 4, 'carte' => 'jean-mballa'])
        ->assertSessionHasNoErrors();

    $customer = VehicleRequest::firstOrFail()->customer;
    expect($customer->acquisition_card_id)->toBe($card->id)->and($customer->owner_user_id)->toBe($seller->id)
        ->and(Task::where('kind', 'card_contact')->where('assigned_user_id', $seller->id)->exists())->toBeTrue()
        ->and(Task::where('kind', 'online_request')->exists())->toBeFalse();
});

it('saves the online page settings and shows the share links', function () {
    $this->actingAs($this->admin)->get('/settings/online-page')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Settings/OnlinePage')->whereNot('rideUrl', null));
    $this->put('/settings/online-page', ['enabled' => true, 'choices' => ['ride', 'prices']])->assertSessionHasNoErrors();
    expect(OnlinePage::enabledChoices($this->organization->fresh()))->toBe(['ride', 'prices']);
    $this->get('/location/dashboard')->assertInertia(fn (Assert $page) => $page->where('share.rideUrl', url("/rdv/{$this->organization->slug}/course")));
});

it('checks accessories, prints the contract, bills extra kilometres and shows the profit per vehicle', function () {
    $this->actingAs($this->admin)->post('/location/settings', ['accessories' => "Roue de secours\nCric", 'km_per_day' => 200, 'extra_km_price' => 150, 'conditions' => 'Plein rendu plein.'])->assertSessionHasNoErrors();
    $this->post('/location/requests', ['store_id' => $this->store->id, 'name' => 'Aline', 'phone' => '077554433', 'need' => 'rent', 'source' => 'agency',
        'starts_at' => '2026-11-16 12:00', 'ends_at' => '2026-11-17 12:00', 'resource_id' => $this->car->id])->assertSessionHasNoErrors();
    $request = VehicleRequest::firstOrFail();
    $this->post("/location/requests/{$request->id}/book", ['resource_id' => $this->car->id, 'deposit' => 100000])->assertSessionHasNoErrors();
    $booking = Booking::findOrFail($request->fresh()->rental_id);
    expect($booking->km_included)->toBe(200);

    $this->get("/location/bookings/{$booking->id}/contract.pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get("/location/bookings/{$booking->id}/inspection/out")->assertInertia(fn (Assert $page) => $page->where('booking.accessories', ['Roue de secours', 'Cric']));

    $this->post("/location/bookings/{$booking->id}/inspection/out", ['mileage' => 10000, 'checklist' => ['Roue de secours', 'Cric']])->assertSessionHasNoErrors();
    $sales = Purchase::count();
    $this->post("/location/bookings/{$booking->id}/inspection/in", ['mileage' => 10260, 'checklist' => ['Roue de secours']])->assertSessionHasNoErrors();
    expect($booking->fresh()->extra_km_amount)->toBe(60 * 150)->and(Purchase::count())->toBe($sales + 1)
        ->and($booking->inspections()->where('phase', 'in')->first()->checklist)->toBe(['Roue de secours']);

    $this->post('/expenses', ['store_id' => $this->store->id, 'spent_on' => '2026-11-16', 'amount' => 25000, 'category' => 'entretien', 'label' => 'Vidange', 'resource_id' => $this->car->id])->assertSessionHasNoErrors();
    expect(Expense::firstOrFail()->resource_id)->toBe($this->car->id);
    $this->get('/location/dashboard')->assertInertia(fn (Assert $page) => $page->where('board.vehicles.0.costs', 25000));
});
