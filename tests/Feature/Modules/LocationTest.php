<?php

use App\Models\BookableResource;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Modules\CarteDigitale\Models\DigitalCard;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Location\Models\Booking;
use App\Modules\Location\Models\RentalAccount;
use App\Modules\Location\Models\VehicleRequest;
use App\Modules\Location\Models\VehicleSale;
use App\Modules\Location\Services\LocationFollowUps;
use App\Modules\Location\Services\LocationSegments;
use App\Support\SectorPresets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Samedi 14 novembre 2026, 10 h.
    $this->travelTo(Carbon::parse('2026-11-14 10:00'));
    $this->organization = Organization::factory()->create(['sector' => 'location', 'whatsapp_api_enabled' => false]);
    SectorPresets::apply($this->organization, true);
    $this->organization->refresh();
    $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $brand->id]);
    $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role_id' => defaultRole($this->organization, 'Super administrateur')->id]);
    $this->customer = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24177001133', 'name' => 'Aline Moussavou', 'consent_status' => 'opt_in'])->customer;
    $this->prado = BookableResource::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'name' => 'Toyota Prado', 'kind' => 'vehicule',
        'category' => '4x4', 'active' => true, 'status' => 'available', 'mileage' => 40000, 'price_day' => 60000]);
});

function locRequest($test, array $extra = []): VehicleRequest
{
    $test->actingAs($test->admin)->post('/location/requests', [
        'store_id' => $test->store->id, 'customer_id' => $test->customer->id, 'need' => 'rent', 'source' => 'whatsapp', 'purpose' => 'event',
        'starts_at' => '2026-11-20 08:00', 'ends_at' => '2026-11-22 08:00', 'with_driver' => true, 'resource_id' => $test->prado->id, ...$extra,
    ])->assertSessionHasNoErrors();

    return VehicleRequest::latest('id')->firstOrFail();
}

it('sets up the rental sector next to the fleet sector', function () {
    expect($this->organization->hasModule('location'))->toBeTrue()
        ->and($this->organization->hasModule('vehicules'))->toBeTrue()
        ->and($this->organization->hasModule('factures'))->toBeTrue()
        ->and(Organization::PUBLIC_SECTORS)->toContain('location')->not->toContain('vehicules');

    $this->actingAs($this->admin)->get('/location/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Location/Dashboard')->has('board.vehicles', 1));
    $this->get('/location/requests')->assertOk();
    $this->get('/location/planning')->assertOk();
    $this->get('/location/sales')->assertOk();
    $this->get('/location/accounts')->assertOk();
});

it('runs the pipeline: request, quote, booking with deposit invoice, and requires a reason when lost', function () {
    $request = locRequest($this);
    expect($request->status)->toBe('new');

    $this->post("/location/requests/{$request->id}/quote", ['driver_rate' => 15000, 'deposit' => 200000])->assertSessionHasNoErrors()->assertSessionHas('whatsapp_url');
    $request->refresh();
    expect($request->status)->toBe('quoted')
        ->and($request->quote->total)->toBe(2 * 60000 + 2 * 15000)
        ->and($request->quote->status)->toBe('sent');

    $this->post("/location/requests/{$request->id}/book", ['resource_id' => $this->prado->id, 'deposit' => 200000, 'deposit_percent' => 30, 'pickup_place' => 'Aéroport Léon-Mba', 'driver_name' => 'Rodrigue'])
        ->assertSessionHasNoErrors();
    $request->refresh();
    $booking = Booking::findOrFail($request->rental_id);
    expect($request->status)->toBe('won')
        ->and($booking->status)->toBe('reserved')
        ->and($booking->driver_name)->toBe('Rodrigue')
        ->and($request->quote->fresh()->status)->toBe('invoiced')
        ->and(Invoice::where('quote_id', $request->quote_id)->where('kind', 'deposit')->value('total'))->toBe(45000)
        ->and($booking->balanceDue())->toBe(150000);

    $other = locRequest($this, ['starts_at' => '2026-12-01 08:00', 'ends_at' => '2026-12-02 08:00']);
    $this->post("/location/requests/{$other->id}/lose", [])->assertSessionHasErrors('lost_reason');
    $this->post("/location/requests/{$other->id}/lose", ['lost_reason' => 'nope'])->assertSessionHasErrors('lost_reason');
    $this->post("/location/requests/{$other->id}/lose", ['lost_reason' => 'price'])->assertSessionHasNoErrors();
    expect($other->fresh()->status)->toBe('lost')->and($other->fresh()->lost_reason)->toBe('price');
});

it('never double-books a vehicle and refuses a vehicle in maintenance', function () {
    $first = locRequest($this);
    $this->post("/location/requests/{$first->id}/book", ['resource_id' => $this->prado->id])->assertSessionHasNoErrors();

    // Chevauchement d'une heure : refusé.
    $second = locRequest($this, ['starts_at' => '2026-11-22 07:00', 'ends_at' => '2026-11-23 08:00']);
    $this->post("/location/requests/{$second->id}/book", ['resource_id' => $this->prado->id])->assertSessionHasErrors('resource_id');
    expect($second->fresh()->status)->toBe('new');

    // Bout à bout : accepté.
    $this->post("/location/requests/{$second->id}/book", ['resource_id' => $this->prado->id, 'starts_at' => '2026-11-22 08:00', 'ends_at' => '2026-11-23 08:00'])->assertSessionHasNoErrors();

    // Réservation directe sur un véhicule en entretien : refusée, sans demande orpheline.
    $this->prado->update(['status' => 'maintenance']);
    $count = VehicleRequest::count();
    $this->post('/location/bookings', ['store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'resource_id' => $this->prado->id,
        'starts_at' => '2026-12-10 08:00', 'ends_at' => '2026-12-11 08:00'])->assertSessionHasErrors('resource_id');
    expect(VehicleRequest::count())->toBe($count);
});

it('records departure and return inspections with photos, then returns or retains the deposit', function () {
    Storage::fake();
    $request = locRequest($this);
    $this->post("/location/requests/{$request->id}/book", ['resource_id' => $this->prado->id, 'deposit' => 200000])->assertSessionHasNoErrors();
    $booking = Booking::findOrFail($request->fresh()->rental_id);

    $this->post("/location/bookings/{$booking->id}/inspection/out", ['mileage' => 40100, 'fuel' => 100, 'photos' => [UploadedFile::fake()->image('avant.jpg')]])->assertSessionHasNoErrors();
    $booking->refresh();
    expect($booking->status)->toBe('ongoing')->and($booking->deposit_status)->toBe('held')->and($booking->inspections()->first()->photos)->toHaveCount(1);
    $this->get("/location/inspections/{$booking->inspections()->first()->id}/photos/0")->assertOk();

    $this->post("/location/bookings/{$booking->id}/inspection/in", ['mileage' => 39000])->assertSessionHasErrors('mileage');
    $this->post("/location/bookings/{$booking->id}/inspection/in", ['mileage' => 40600, 'fuel' => 50, 'damages' => 'Rayure portière arrière'])->assertSessionHasNoErrors();
    expect($booking->fresh()->status)->toBe('returned');

    // Retenue sans motif : refusée ; retenue supérieure à la caution : refusée.
    $this->post("/location/bookings/{$booking->id}/deposit", ['retained' => 30000])->assertSessionHasErrors('reason');
    $this->post("/location/bookings/{$booking->id}/deposit", ['retained' => 250000, 'reason' => 'damage'])->assertSessionHasErrors('retained');

    $sales = Purchase::count();
    $this->post("/location/bookings/{$booking->id}/deposit", ['retained' => 30000, 'reason' => 'damage', 'note' => 'Rayure'])->assertSessionHasNoErrors()->assertSessionHas('whatsapp_url');
    $booking->refresh();
    expect($booking->deposit_status)->toBe('partial')
        ->and($booking->depositRefund())->toBe(170000)
        ->and(Purchase::count())->toBe($sales + 1)
        ->and(urldecode(session('whatsapp_url')))->toContain('30 000');

    $this->post("/location/bookings/{$booking->id}/deposit", ['retained' => 0])->assertSessionHasErrors('deposit');
});

it('lists quote follow-ups at 24 h then 72 h, the reminder the day before, and the thank-you after return', function () {
    $request = locRequest($this);
    $this->post("/location/requests/{$request->id}/quote", [])->assertSessionHasNoErrors();
    $kinds = fn () => collect(LocationFollowUps::due($this->organization, [$this->store->id]))->pluck('kind')->all();

    expect($kinds())->toBe([]);
    $this->travel(25)->hours();
    expect($kinds())->toBe(['quote_24']);
    $this->post("/location/follow-ups/quote_24/{$request->id}")->assertSessionHasNoErrors();
    expect($kinds())->toBe([]);
    $this->travel(48)->hours();
    expect($kinds())->toBe(['quote_72']);
    LocationFollowUps::markDone($this->organization->id, 'quote_72', $request->id);
    expect($kinds())->toBe([]);

    // Réservation pour demain : rappel de la veille.
    $this->travelTo(Carbon::parse('2026-11-19 18:00'));
    $this->post("/location/requests/{$request->id}/book", ['resource_id' => $this->prado->id])->assertSessionHasNoErrors();
    $items = LocationFollowUps::due($this->organization, [$this->store->id]);
    expect(collect($items)->pluck('kind')->all())->toBe(['reminder'])->and($items[0]['wa_url'])->toStartWith('https://wa.me/24177001133');

    $booking = Booking::findOrFail($request->fresh()->rental_id);
    LocationFollowUps::markDone($this->organization->id, 'reminder', $booking->id);
    $this->travelTo(Carbon::parse('2026-11-20 08:30'));
    $this->post("/location/bookings/{$booking->id}/inspection/out", ['mileage' => 40100])->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-11-22 09:00'));
    $this->post("/location/bookings/{$booking->id}/inspection/in", ['mileage' => 40500])->assertSessionHasNoErrors();
    expect($kinds())->toBe(['thanks']);
});

it('applies the negotiated company rate and exposes rental segments to campaigns', function () {
    RentalAccount::create(['organization_id' => $this->organization->id, 'customer_id' => $this->customer->id, 'discount_percent' => 10]);
    $request = locRequest($this, ['with_driver' => false]);
    $this->post("/location/requests/{$request->id}/quote", [])->assertSessionHasNoErrors();
    expect($request->fresh()->quote->total)->toBe(2 * 54000);

    expect(LocationSegments::query($this->organization->id, 'event')->pluck('customer_id')->all())->toContain($this->customer->id)
        ->and(LocationSegments::query($this->organization->id, 'business')->pluck('id')->all())->toContain($this->customer->id);

    $this->get('/customers?follow=location:event')->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.follow', 'location:event'));
});

it('follows buyers of a vehicle for sale and sells it in instalments', function () {
    Storage::fake();
    $this->actingAs($this->admin)->post("/location/vehicles/{$this->prado->id}/for-sale", ['sale_price' => 18000000, 'photos' => [UploadedFile::fake()->image('prado.jpg')]])->assertSessionHasNoErrors();
    expect($this->prado->fresh()->status)->toBe('for_sale');

    $this->post("/location/vehicles/{$this->prado->id}/buyers", ['store_id' => $this->store->id, 'name' => 'Paul Obame', 'phone' => '077112233'])->assertSessionHasNoErrors();
    $this->post("/location/vehicles/{$this->prado->id}/buyers", ['store_id' => $this->store->id, 'customer_id' => $this->customer->id])->assertSessionHasNoErrors();
    [$paul, $aline] = VehicleSale::orderBy('id')->get()->all();

    $this->post("/location/sales/{$paul->id}/move", ['status' => 'test_drive'])->assertSessionHasErrors('test_drive_at');
    $this->post("/location/sales/{$paul->id}/move", ['status' => 'test_drive', 'test_drive_at' => '2026-11-16 10:00'])->assertSessionHasNoErrors();
    $this->post("/location/sales/{$paul->id}/move", ['status' => 'offer', 'offer_amount' => 17000000, 'trade_in_label' => 'Corolla 2012', 'trade_in_value' => 2000000])->assertSessionHasNoErrors();
    $this->post("/location/sales/{$paul->id}/move", ['status' => 'reserved'])->assertSessionHasNoErrors();
    $this->post("/location/sales/{$aline->id}/move", ['status' => 'reserved'])->assertSessionHasErrors('status');

    $this->get('/location/sales')->assertInertia(fn (Assert $page) => $page->where('vehicles.0.sale_status', 'reserved')->has('vehicles.0.photos', 1));

    $this->post("/location/sales/{$paul->id}/sell", ['price' => 15000000, 'installments' => 3])->assertSessionHasNoErrors();
    $paul->refresh();
    expect($paul->status)->toBe('sold')
        ->and($paul->schedule->installments)->toHaveCount(3)
        ->and((int) $paul->schedule->total_amount)->toBe(15000000)
        ->and($this->prado->fresh()->status)->toBe('sold')
        ->and($aline->fresh()->status)->toBe('lost');
});

it('loads the rental demo company', function () {
    $this->artisan('demo:location-vehicules')->assertSuccessful();
    $organization = Organization::where('slug', 'demo-location-vehicules')->firstOrFail();
    $user = User::where('email', 'directeur@demo-location.test')->firstOrFail();

    expect(BookableResource::where('organization_id', $organization->id)->where('status', '!=', 'sold')->count())->toBe(8)
        ->and(VehicleSale::where('organization_id', $organization->id)->where('status', 'sold')->count())->toBe(1)
        ->and(Booking::where('organization_id', $organization->id)->where('status', 'ongoing')->count())->toBe(2);

    $this->actingAs($user)->get('/location/dashboard')->assertOk();
    $this->get('/location/planning')->assertOk();
    $this->get('/location/requests')->assertOk();
});

it('shares a public listing, reminds the test drive the day before, and shows a Location tab on the customer card', function () {
    Storage::fake();
    $this->store->update(['booking_phone' => '24166001122']);
    $this->actingAs($this->admin)->post("/location/vehicles/{$this->prado->id}/for-sale", ['sale_price' => 18000000, 'photos' => [UploadedFile::fake()->image('prado.jpg')]])->assertSessionHasNoErrors();
    $token = $this->prado->fresh()->sale_token;
    expect($token)->not->toBeNull();

    auth()->logout();
    $this->get("/annonce/{$token}")->assertOk()->assertSee('Toyota Prado')->assertSee('18 000 000 FCFA')->assertSee('wa.me/24166001122', false);
    $this->get("/annonce/{$token}/photos/0")->assertOk();
    $this->get('/annonce/'.str_repeat('x', 32))->assertNotFound();

    $this->actingAs($this->admin)->post("/location/vehicles/{$this->prado->id}/buyers", ['store_id' => $this->store->id, 'customer_id' => $this->customer->id])->assertSessionHasNoErrors();
    $sale = VehicleSale::firstOrFail();
    $this->post("/location/sales/{$sale->id}/move", ['status' => 'test_drive', 'test_drive_at' => '2026-11-15 11:00'])->assertSessionHasNoErrors();
    $items = collect(LocationFollowUps::due($this->organization, [$this->store->id]));
    expect($items->pluck('kind')->all())->toBe(['test_drive']);
    $this->post("/location/follow-ups/test_drive/{$sale->id}")->assertSessionHasNoErrors();
    expect(LocationFollowUps::due($this->organization, [$this->store->id]))->toBe([]);

    $this->get("/customers/{$this->customer->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('locationModule.sales', 1));
});

it('loads the Yamoo Drive demo next to the other demos', function () {
    $this->artisan('demo:location-vehicules')->assertSuccessful();
    $this->artisan('demo:yamoo-drive')->assertSuccessful();
    $organization = Organization::where('slug', 'demo-yamoo-drive')->firstOrFail();

    expect(Organization::where('slug', 'demo-location-vehicules')->exists())->toBeTrue()
        ->and($organization->stores()->pluck('city')->all())->toBe(['Douala', 'Yaoundé', 'Bafoussam'])
        ->and($organization->hasModule('vehicules'))->toBeTrue()
        ->and(Booking::where('organization_id', $organization->id)->where('deposit_status', 'held')->where('status', 'returned')->count())->toBe(1)
        ->and(VehicleSale::where('organization_id', $organization->id)->where('status', 'sold')->firstOrFail()->schedule->installments)->toHaveCount(3);

    $this->post('/login', ['email' => 'directeur@yamoo-drive.test', 'password' => 'Demo-Yamoo-2026'])->assertRedirect();
    $this->assertAuthenticated();
    $this->get('/location/dashboard')->assertOk();
    $this->get('/location/requests')->assertOk();
    $this->get('/location/planning')->assertOk();
    $this->get('/vehicles')->assertOk();

    // Pipeline commercial : commerciaux avec cartes digitales, prospects à toutes les étapes, relances dues.
    $customers = Customer::where('organization_id', $organization->id);
    expect($organization->fresh()->hasModule('prospects'))->toBeTrue()
        ->and(DigitalCard::where('organization_id', $organization->id)->count())->toBe(7)
        ->and((clone $customers)->whereNotNull('prospect_stage')->distinct()->pluck('prospect_stage')->sort()->values()->all())->toBe(['demande', 'devis', 'essai', 'gagne', 'perdu'])
        ->and((clone $customers)->where('acquisition_source', 'carte')->whereNotNull('prospect_stage')->count())->toBeGreaterThan(0);
    $this->get('/prospects')->assertOk();
    $this->get('/cards')->assertOk();
});
