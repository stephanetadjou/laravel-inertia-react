<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use Illuminate\Support\Carbon;

it('sets up the programme and every case with the expected figures, once', function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00'));
    $organization = Organization::factory()->create(['sector' => 'commerce', 'slug' => 'retailgroup-mode']);

    foreach (['Adidas', 'Mango', 'Carré'] as $name) {
        $brand = Brand::factory()->create(['organization_id' => $organization->id, 'name' => $name]);
        Store::factory()->count(2)->create(['organization_id' => $organization->id, 'brand_id' => $brand->id]);
    }

    $old = LoyaltyProgram::create(['organization_id' => $organization->id, 'name' => 'Fidélité novembre', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $other = LoyaltyProgram::create(['organization_id' => $organization->id, 'name' => 'Programme dec 2026', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-30']);

    $this->artisan('demo:fidelite-cas')->assertSuccessful();
    $tickets = Purchase::count();
    $this->artisan('demo:fidelite-cas')->assertSuccessful();

    expect(Purchase::count())->toBe($tickets)
        ->and($old->fresh()->name)->toBe('Fidélité 2026-2027')
        ->and($other->fresh()->starts_on->toDateString())->toBe('2027-10-01')
        ->and(Customer::where('name', 'like', 'Cas %')->count())->toBe(14)
        ->and(Purchase::where('receipt_number', 'CAS15-1')->value('contact_id'))->toBeNull()
        ->and(Contact::where('phone_e164', 'like', '2416900%')->count())->toBe(16);
});

it('stops when the demo company is missing', function () {
    $this->artisan('demo:fidelite-cas')->assertFailed();
});
