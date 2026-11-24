<?php

use App\Models\User;
use App\Modules\Facturation\Models\Invoice;
use App\Modules\Facturation\Services\Invoicing;
use Inertia\Testing\AssertableInertia as Assert;

// Devis express : devis composé sur le site (?offre=devis), enregistré après
// l'inscription, offre Gratuit limitée à 3 devis par mois.

function signUpFromQuotePage($test, string $query = ''): User
{
    $test->get('/register?offre=devis'.$query)->assertOk();
    $test->post('/register', [
        'organization_name' => 'Garage Ndong Auto', 'sector' => 'technique', 'store_name' => 'Garage Ndong Auto', 'name' => 'Jean Ndong',
        'email' => 'jean@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
    ]);

    return User::where('email', 'jean@example.com')->firstOrFail();
}

it('saves the quote composed on the website right after signing up', function () {
    $lines = urlencode(json_encode([['Vidange moteur', 1, 35000], ['Main-d’œuvre', 2, 10000]]));
    $this->get("/register?offre=devis&entreprise=Garage%20Ndong%20Auto&client=Serge%20Mba&client_tel=066001122&lignes={$lines}&remise=5000&acompte=30&validite=15")
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('prefill.offer', 'devis')->where('prefill.organization_name', 'Garage Ndong Auto'));

    $response = $this->post('/register', [
        'organization_name' => 'Garage Ndong Auto', 'sector' => 'technique', 'store_name' => 'Garage Ndong Auto', 'name' => 'Jean Ndong',
        'email' => 'jean@example.com', 'password' => 'Secret-123456', 'password_confirmation' => 'Secret-123456',
    ]);

    $quote = Invoice::where('type', Invoice::TYPE_QUOTE)->with('lines', 'customer')->firstOrFail();
    $response->assertRedirect("/invoices/{$quote->id}");
    $organization = $quote->organization()->first() ?? User::where('email', 'jean@example.com')->first()->organization;
    expect($quote->total)->toBe(50000)->and($quote->discount_amount)->toBe(5000)->and($quote->deposit_percent)->toBe(30)
        ->and($quote->lines)->toHaveCount(2)->and($quote->customer->name)->toBe('Serge Mba')
        ->and($quote->customer->phone_e164)->toBe('24166001122')
        ->and($organization->pack())->toBe('gratuit')->and($organization->hasModule('factures'))->toBeTrue();
});

it('opens a new quote when the website sent no line', function () {
    signUpFromQuotePage($this);
    expect(Invoice::count())->toBe(0);
});

it('blocks the 4th quote of the month on the free plan', function () {
    $user = signUpFromQuotePage($this);
    $store = $user->organization->stores()->first();
    $customer = app(Invoicing::class)->createFromDraft($user, Invoicing::draftFromQuery(['client' => 'Serge', 'lignes' => json_encode([['A', 1, 1000]])]))->customer_id;
    $payload = ['type' => 'quote', 'customer_id' => $customer, 'store_id' => $store->id, 'lines' => [['label' => 'Vidange', 'quantity' => 1, 'unit_price' => 35000]]];

    $this->actingAs($user)->post('/invoices', $payload)->assertRedirect();
    $this->actingAs($user)->post('/invoices', $payload)->assertRedirect();
    expect(Invoice::where('type', 'quote')->count())->toBe(3);

    $this->actingAs($user)->post('/invoices', $payload)->assertSessionHas('error', Invoicing::QUOTA_MESSAGE);
    expect(Invoice::where('type', 'quote')->count())->toBe(3);
    $this->actingAs($user)->get('/invoices/create?type=quote')->assertInertia(fn (Assert $page) => $page->where('quota.blocked', true)->where('quota.limit', 3));

    // Offre payante : illimité.
    $user->organization->forceFill(['plan' => 'solo'])->save();
    $this->actingAs($user->fresh())->post('/invoices', $payload)->assertSessionMissing('error');
    expect(Invoice::where('type', 'quote')->count())->toBe(4);
});
