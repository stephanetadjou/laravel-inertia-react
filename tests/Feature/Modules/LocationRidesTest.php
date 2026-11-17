<?php

use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Task;
use App\Models\User;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RideFare;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Services\LocationFollowUps;
use App\Modules\Location\Services\LocationSegments;
use App\Support\SectorPresets;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-16 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'location', 'whatsapp_api_enabled' => false]);
    SectorPresets::apply($this->organization, true);
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->car = BookableResource::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Toyota Camry', 'kind' => 'vehicule',
        'category' => 'berline', 'active' => true, 'status' => 'available', 'price_day' => 50000]);
    $this->fare = RideFare::create(['organization_id' => $this->organization->id, 'label' => 'Aéroport ↔ Centre-ville', 'price' => 15000, 'minutes' => 60]);
});

function bookRide($test, string $at, array $extra = [])
{
    return $test->actingAs($test->admin)->post('/location/rides', [
        'store_id' => $test->store->id, 'name' => 'Paul Obame', 'phone' => '077112233', 'resource_id' => $test->car->id,
        'starts_at' => $at, 'ride_to' => 'Centre-ville', 'ride_passengers' => 2, 'ride_luggage' => 2, 'flight_number' => 'AF946',
        'fare_id' => $test->fare->id, 'driver_name' => 'Jean', ...$extra,
    ]);
}

it('books a ride without overlap, runs its steps and bills it at the end', function () {
    bookRide($this, '2026-11-16 18:00')->assertSessionHasNoErrors()->assertSessionHas('whatsapp_url');
    $ride = Booking::where('kind', 'ride')->firstOrFail();
    expect($ride->amount)->toBe(15000)->and($ride->ride_status)->toBe('booked')->and($ride->ends_at->format('H:i'))->toBe('19:00')
        ->and(urldecode(session('whatsapp_url')))->toContain('Jean')->toContain('Toyota Camry');

    // Le véhicule est pris pendant la durée estimée de la course.
    bookRide($this, '2026-11-16 18:30', ['phone' => '077445566'])->assertSessionHasErrors('resource_id');
    bookRide($this, '2026-11-16 19:00', ['phone' => '077445566'])->assertSessionHasNoErrors();

    $this->post("/location/rides/{$ride->id}/status", ['status' => 'done'])->assertSessionHasErrors('ride');
    foreach (['en_route', 'on_board'] as $step) {
        $this->post("/location/rides/{$ride->id}/status", ['status' => $step])->assertSessionHasNoErrors();
    }
    $this->post("/location/rides/{$ride->id}/status", ['status' => 'cancelled'])->assertSessionHasErrors('ride');
    $sales = Purchase::count();
    $this->post("/location/rides/{$ride->id}/status", ['status' => 'done', 'payment_method' => 'cash'])->assertSessionHasNoErrors()->assertSessionHas('whatsapp_url');
    expect($ride->fresh()->ride_status)->toBe('done')->and($ride->fresh()->status)->toBe('returned')->and(Purchase::count())->toBe($sales + 1);

    expect(LocationSegments::query($this->organization->id, 'airport')->pluck('customer_id')->all())->toContain($ride->customer_id);
    $this->get('/location/dashboard')->assertInertia(fn ($page) => $page->where('board.rides.count', 2)->where('board.rides.revenue', 15000));
});

it('reminds a ride booked in advance 2 hours before, then thanks the passenger', function () {
    $this->travelTo(Carbon::parse('2026-11-15 09:00'));
    bookRide($this, '2026-11-16 12:00')->assertSessionHasNoErrors();
    $ride = Booking::where('kind', 'ride')->firstOrFail();

    $this->travelTo(Carbon::parse('2026-11-16 10:30'));
    $kinds = fn () => collect(LocationFollowUps::due($this->organization, [$this->store->id]))->pluck('kind')->all();
    expect($kinds())->toBe(['ride_reminder']);
    LocationFollowUps::markDone($this->organization->id, 'ride_reminder', $ride->id);
    expect($kinds())->toBe([]);

    foreach (['en_route', 'on_board', 'done'] as $step) {
        $this->post("/location/rides/{$ride->id}/status", ['status' => $step])->assertSessionHasNoErrors();
    }
    expect($kinds())->toBe(['thanks']);
});

it('turns a ride request into a booked ride', function () {
    $this->actingAs($this->admin)->post('/location/requests', ['store_id' => $this->store->id, 'name' => 'Aline', 'phone' => '077998877', 'need' => 'ride', 'source' => 'whatsapp',
        'ride_from' => 'Aéroport', 'ride_to' => 'Owendo', 'starts_at' => '2026-11-16 20:00'])->assertSessionHasNoErrors();
    $request = VehicleRequest::where('need', 'ride')->firstOrFail();
    expect($request->summary())->toContain('Aéroport → Owendo');

    $this->post("/location/requests/{$request->id}/ride", ['resource_id' => $this->car->id, 'starts_at' => '2026-11-16 20:00', 'ride_from' => 'Aéroport', 'ride_to' => 'Owendo', 'price' => 12000])
        ->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('won')->and(Booking::find($request->fresh()->rental_id)->amount)->toBe(12000);
});

it('takes a public ride request with anti-abuse checks', function () {
    $this->get("/rdv/{$this->organization->slug}/course")->assertOk()->assertInertia(fn ($page) => $page->component('Location/RideRequest')->has('fares', 1));

    $payload = ['ride_from' => 'Ma position : https://maps.google.com/?q=0.4,9.4', 'ride_to' => 'Centre-ville', 'when' => 'now', 'ride_passengers' => 1, 'name' => 'Grâce', 'phone' => '077123456'];
    $this->post("/rdv/{$this->organization->slug}/course", [...$payload, 'phone' => '12'])->assertSessionHasErrors('phone');
    $this->post("/rdv/{$this->organization->slug}/course", [...$payload, 'website' => 'spam'])->assertSessionHasErrors('website');
    $this->post("/rdv/{$this->organization->slug}/course", $payload)->assertRedirect("/rdv/{$this->organization->slug}/course")->assertSessionHas('ride_sent');

    $request = VehicleRequest::where('need', 'ride')->firstOrFail();
    expect($request->source)->toBe('online')->and($request->customer->phone_e164)->toBe('24177123456')
        ->and(Task::where('kind', 'ride_request')->where('customer_id', $request->customer_id)->exists())->toBeTrue();

    // Quota : 5 envois par minute et par adresse.
    foreach (range(1, 4) as $i) {
        $this->post("/rdv/{$this->organization->slug}/course", [...$payload, 'phone' => '07712345'.$i]);
    }
    $this->post("/rdv/{$this->organization->slug}/course", $payload)->assertStatus(429);

    // Une entreprise sans le module Location n'a pas cette page.
    $other = Organization::factory()->create(['sector' => 'beaute']);
    $this->get("/rdv/{$other->slug}/course")->assertNotFound();
});
