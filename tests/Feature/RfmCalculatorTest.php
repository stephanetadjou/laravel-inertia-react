<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\RfmCalculator;

/** Un client (fiche + contact dans la boutique) et ses achats [jours, montant]. */
function rfmBuyer(Store $store, array $purchases): Customer
{
    $contact = Contact::factory()->create(['store_id' => $store->id]);

    foreach ($purchases as [$daysAgo, $amount]) {
        Purchase::factory()->create([
            'contact_id' => $contact->id,
            'store_id' => $store->id,
            'amount' => $amount,
            'purchased_at' => now()->subDays($daysAgo),
        ]);
    }

    return $contact->fresh()->customer;
}

function rfmFor(Customer $customer, array $storeIds): array
{
    return app(RfmCalculator::class)->forCustomer($customer->fresh(), $storeIds);
}

it('turns a centile rank into a 1 to 5 score, ties sharing the same score', function () {
    // 5 acheteurs distincts : un par quintile.
    expect(collect(range(0, 4))->map(fn ($below) => RfmCalculator::quintile($below, 1, 5))->all())
        ->toBe([1, 2, 3, 4, 5]);

    // Acheteur seul, ou tout le monde ex-aequo : score neutre.
    expect(RfmCalculator::quintile(0, 1, 1))->toBe(3)
        ->and(RfmCalculator::quintile(0, 10, 10))->toBe(3)
        ->and(RfmCalculator::quintile(0, 0, 0))->toBe(3);
});

it('maps scores to readable segments', function (int $r, int $f, int $m, int $frequency, string $segment) {
    expect(RfmCalculator::segmentKey($r, $f, $m, $frequency))->toBe($segment);
})->with([
    'champions' => [5, 5, 4, 8, 'champions'],
    'nouveau client' => [5, 1, 2, 1, 'new'],
    'fidèle' => [3, 4, 3, 5, 'loyal'],
    'potentiel' => [4, 2, 2, 2, 'potential'],
    'à ne pas perdre' => [1, 5, 5, 9, 'cant_lose'],
    'à risque' => [2, 4, 3, 4, 'at_risk'],
    'hibernant' => [2, 2, 1, 2, 'hibernating'],
    'perdu' => [1, 1, 2, 1, 'lost'],
    'achat unique ancien' => [1, 3, 2, 1, 'lost'],
    'achat unique ancien, gros montant' => [2, 3, 5, 1, 'hibernating'],
]);

it('scores a customer against every buyer of the given stores', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $otherStore = Store::factory()->create(['organization_id' => $organization->id]);
    $storeIds = [$store->id, $otherStore->id];

    $best = rfmBuyer($store, [[2, 90000], [20, 60000], [50, 40000], [100, 30000]]);
    rfmBuyer($otherStore, [[30, 40000], [60, 20000], [120, 10000]]);
    rfmBuyer($store, [[90, 20000], [200, 15000]]);
    rfmBuyer($otherStore, [[150, 10000]]);
    $worst = rfmBuyer($store, [[400, 5000]]);

    // Acheteurs d'une autre organisation : ignorés.
    rfmBuyer(Store::factory()->create(), [[1, 900000], [2, 900000], [3, 900000], [4, 900000], [5, 900000]]);

    $rfm = rfmFor($best, $storeIds);

    expect($rfm['scores'])->toBe(['r' => 5, 'f' => 5, 'm' => 5])
        ->and($rfm['buyers'])->toBe(5)
        ->and($rfm['segment']['key'])->toBe('champions')
        ->and($rfm['recency_days'])->toBe(2)
        ->and($rfm['frequency'])->toBe(4)
        ->and($rfm['frequency_12m'])->toBe(4)
        ->and($rfm['monetary'])->toBe(220000)
        ->and($rfm['average_basket'])->toBe(55000);

    $rfm = rfmFor($worst, $storeIds);

    expect($rfm['scores'])->toBe(['r' => 1, 'f' => 2, 'm' => 1])
        ->and($rfm['frequency_12m'])->toBe(0)
        ->and($rfm['segment']['key'])->toBe('lost');
});

it('adds up the purchases of every store of the customer, within the given stores only', function () {
    $organization = Organization::factory()->create();
    $mango = Store::factory()->create(['organization_id' => $organization->id]);
    $adidas = Store::factory()->create(['organization_id' => $organization->id]);

    $customer = rfmBuyer($mango, [[10, 30000]]);
    $adidasContact = Contact::factory()->create(['store_id' => $adidas->id, 'phone_e164' => $customer->phone_e164]);
    Purchase::factory()->create(['contact_id' => $adidasContact->id, 'store_id' => $adidas->id, 'amount' => 50000, 'purchased_at' => now()->subDays(3)]);

    expect($adidasContact->fresh()->customer_id)->toBe($customer->id)
        ->and(rfmFor($customer, [$mango->id, $adidas->id]))->toMatchArray(['frequency' => 2, 'monetary' => 80000, 'recency_days' => 3])
        ->and(rfmFor($customer, [$mango->id]))->toMatchArray(['frequency' => 1, 'monetary' => 30000, 'recency_days' => 10]);
});

it('gives tied buyers the same scores', function () {
    $store = Store::factory()->create();
    $first = rfmBuyer($store, [[10, 30000]]);
    $second = rfmBuyer($store, [[10, 30000]]);
    rfmBuyer($store, [[300, 5000]]);

    expect(rfmFor($first, [$store->id])['scores'])
        ->toBe(rfmFor($second, [$store->id])['scores'])
        ->toBe(['r' => 4, 'f' => 3, 'm' => 4]);
});

it('gives neutral scores to the only buyer', function () {
    $store = Store::factory()->create();
    $rfm = rfmFor(rfmBuyer($store, [[5, 25000], [40, 15000]]), [$store->id]);

    expect($rfm['scores'])->toBe(['r' => 3, 'f' => 3, 'm' => 3])
        ->and($rfm['buyers'])->toBe(1)
        ->and($rfm['segment']['key'])->toBe('loyal');
});

it('reports customers without purchases as such', function () {
    $store = Store::factory()->create();
    rfmBuyer($store, [[5, 25000]]);
    $customer = rfmBuyer($store, []);

    $rfm = rfmFor($customer, [$store->id]);

    expect($rfm['scores'])->toBeNull()
        ->and($rfm['recency_days'])->toBeNull()
        ->and($rfm['frequency'])->toBe(0)
        ->and($rfm['monetary'])->toBe(0)
        ->and($rfm['buyers'])->toBe(1)
        ->and($rfm['segment']['label'])->toBe('Pas encore d\'achat');
});

it('shows the rfm profile on the customer page', function () {
    $organization = Organization::factory()->create();
    $store = Store::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $customer = rfmBuyer($store, [[3, 70000], [30, 50000]]);
    rfmBuyer($store, [[200, 10000]]);

    $this->actingAs($user)->get("/customers/{$customer->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->where('rfm.scores', ['r' => 4, 'f' => 4, 'm' => 4])
            ->where('rfm.segment.key', 'champions')
            ->where('rfm.segment.label', 'Champions')
            ->where('rfm.last_purchase_at', now()->subDays(3)->toDateString())
            ->where('rfm.average_basket', 60000),
        );
});
