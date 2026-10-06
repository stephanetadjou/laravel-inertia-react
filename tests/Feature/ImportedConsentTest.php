<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\ImportedConsent;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    $this->organization = Organization::factory()->create();
    $this->store = Store::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Adidas']);
    $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
});

it('reads yes / no answers in the usual forms', function (?string $value, string|null|false $expected) {
    expect(ImportedConsent::parse($value))->toBe($expected);
})->with([
    'oui' => ['oui', 'opt_in'],
    'Oui majuscule' => [' Oui ', 'opt_in'],
    'x' => ['x', 'opt_in'],
    '1' => ['1', 'opt_in'],
    'opt-in' => ['Opt-in', 'opt_in'],
    'non' => ['non', 'opt_out'],
    'refusé' => ['refusé', 'opt_out'],
    '0' => ['0', 'opt_out'],
    'vide' => ['', null],
    'null' => [null, null],
    'inconnu' => ['peut-être', false],
]);

it('sets the consent of imported contacts from the consentement column', function () {
    $existing = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24166000111', 'consent_status' => 'opt_in']);

    $csv = "nom,telephone,consentement\n".
        "Aïcha Mba,77451288,oui\n".
        "Brice Obame,66210347,non\n".
        "Carine,62378105,\n".
        "Denis,74092357,peut-être\n".
        "Déjà client,66000111,\n";

    $preview = $this->actingAs($this->user)->post('/imports/preview', [
        'store_id' => $this->store->id,
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ]);
    $preview->assertInertia(fn ($page) => $page->where('preview.consents', ['opt_in' => 1, 'opt_out' => 1]));

    $this->actingAs($this->user)->post('/imports', [
        'store_id' => $this->store->id,
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ])->assertSessionHas('success');

    $consent = fn (string $phone) => Contact::where('phone_e164', $phone)->value('consent_status');
    expect($consent('24177451288'))->toBe('opt_in');
    expect($consent('24166210347'))->toBe('opt_out');
    expect($consent('24162378105'))->toBe('unknown');
    // Valeur non reconnue : contact importé, consentement inchangé.
    expect($consent('24174092357'))->toBe('unknown');
    // Cellule vide : le statut existant est conservé.
    expect($existing->fresh()->consent_status)->toBe('opt_in');

    // Un accord fait passer la fiche en Prospect.
    expect(Customer::where('phone_e164', '24177451288')->value('lifecycle_stage'))->toBe(Customer::STAGE_PROSPECT);
});

it('sets the consent from a purchase import, the latest answer winning', function () {
    $refused = Contact::factory()->create(['store_id' => $this->store->id, 'phone_e164' => '24166210347', 'consent_status' => 'opt_out']);

    $csv = "telephone,boutique,nom,prix,date_achat,consentement\n".
        "77451288,Adidas,Aïcha Mba,25000,2026-09-20,oui\n".
        "66210347,Adidas,,10000,2026-09-21,oui\n".
        "62378105,Adidas,Carine,5000,2026-09-22,\n";

    $this->actingAs($this->user)->post('/purchases/preview', ['file' => UploadedFile::fake()->createWithContent('achats.csv', $csv)])
        ->assertInertia(fn ($page) => $page->where('preview.consents', ['opt_in' => 2, 'opt_out' => 0]));

    $this->actingAs($this->user)->post('/purchases', ['file' => UploadedFile::fake()->createWithContent('achats.csv', $csv)])
        ->assertSessionHas('success');

    expect(Contact::where('phone_e164', '24177451288')->value('consent_status'))->toBe('opt_in');
    // Le client avait refusé, il a dit oui en caisse : la dernière réponse l'emporte.
    expect($refused->fresh()->consent_status)->toBe('opt_in');
    expect(Contact::where('phone_e164', '24162378105')->value('consent_status'))->toBe('unknown');
});
