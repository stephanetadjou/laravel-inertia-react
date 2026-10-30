<?php

use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\Group;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('loads the fashion demo group through the import engine, idempotently', function () {
    $other = Organization::factory()->create(['name' => 'Autre entreprise']);

    $this->artisan('demo:retail', ['--tickets' => 80])->assertSuccessful();

    $organization = Organization::where('slug', 'demo-retail-mode')->sole();
    $stores = Store::where('organization_id', $organization->id)->with('brand')->get();

    expect($organization->sector)->toBe('commerce')
        ->and($organization->analysisLevel())->toBe('family')
        ->and($organization->loyaltySettings()['enabled'])->toBeTrue()
        ->and($stores->pluck('name', 'brand.name')->all())->toBe([
            'Adidas' => 'Adidas Glass',
            'Grand Store' => 'Grand Store Glass',
            'Mango' => 'Mango Centre-ville',
            'Carré' => 'Carré Centre-ville',
        ]);

    $roles = User::where('organization_id', $organization->id)->with('role')->get()->pluck('role.name')->unique()->all();
    expect($roles)->toContain('Admin groupe', 'Direction', 'Responsable de marque', 'Responsable de boutique', 'Marketing', 'Opérateur d’import');

    $storeIds = $stores->pluck('id');
    $batches = ImportBatch::where('organization_id', $organization->id)->get();
    expect(Purchase::whereIn('store_id', $storeIds)->count())->toBeGreaterThan(50)
        ->and(Purchase::whereIn('store_id', $storeIds)->where('type', 'return')->exists())->toBeTrue()
        ->and(Purchase::whereIn('store_id', $storeIds)->whereNull('contact_id')->exists())->toBeTrue()
        ->and(Purchase::withCancelled()->whereIn('store_id', $storeIds)->where('status', 'cancelled')->exists())->toBeTrue()
        ->and($batches->pluck('status')->all())->toContain('completed_with_errors', 'cancelled')
        ->and(CustomerMatchReview::where('organization_id', $organization->id)->exists())->toBeTrue()
        ->and(Customer::where('organization_id', $organization->id)->where('brands_count', '>=', 2)->exists())->toBeTrue()
        ->and(Group::where('organization_id', $organization->id)->count())->toBeGreaterThanOrEqual(3);

    Storage::disk('local')->assertExists('demo/exemple-import-ventes-mode.xlsx');

    // Relancée sans --fresh : rien n'est dupliqué.
    $tickets = Purchase::whereIn('store_id', $storeIds)->count();
    $this->artisan('demo:retail', ['--tickets' => 80])->assertSuccessful();
    expect(Organization::where('slug', 'demo-retail-mode')->count())->toBe(1)
        ->and(Purchase::whereIn('store_id', $storeIds)->count())->toBe($tickets);

    // --fresh ne supprime que l'entreprise de démonstration.
    $this->artisan('demo:retail', ['--tickets' => 40, '--fresh' => true])->assertSuccessful();
    expect(Organization::find($organization->id))->toBeNull()
        ->and(Organization::where('slug', 'demo-retail-mode')->count())->toBe(1)
        ->and(Organization::find($other->id))->not->toBeNull();
});

it('loads the restaurant and distribution demos', function () {
    $this->artisan('demo:retail', ['--type' => 'restaurant', '--tickets' => 40])->assertSuccessful();
    $this->artisan('demo:retail', ['--type' => 'distribution', '--tickets' => 40])->assertSuccessful();

    $distribution = Organization::where('slug', 'demo-retail-distribution')->sole();
    expect($distribution->analysisLevel())->toBe('category')
        ->and(Customer::where('organization_id', $distribution->id)->where('type', 'business')->whereNotNull('external_code')->exists())->toBeTrue();

    $restaurant = Organization::where('slug', 'demo-retail-restaurant')->sole();
    expect(Purchase::whereIn('store_id', $restaurant->stores()->pluck('id'))->count())->toBeGreaterThan(20);
});

it('refuses an unknown demo type', function () {
    $this->artisan('demo:retail', ['--type' => 'bijouterie'])->assertFailed();
});
