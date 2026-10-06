<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\User;
use App\Services\ImportedDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
});

it('reads the usual date formats and Excel serial numbers', function (string $input, ?string $expected) {
    expect(ImportedDate::parse($input))->toBe($expected);
})->with([
    'ISO' => ['2026-03-15', '2026-03-15'],
    'ISO avec heure' => ['2026-03-15 00:00:00', '2026-03-15'],
    'jj/mm/aaaa' => ['15/03/2026', '2026-03-15'],
    'jj-mm-aaaa' => ['15-03-2026', '2026-03-15'],
    'jj.mm.aaaa' => ['15.03.2026', '2026-03-15'],
    'jj/mm/aa' => ['15/03/26', '2026-03-15'],
    'série Excel' => ['46096', '2026-03-15'],
    'date impossible' => ['31/02/2026', null],
    'texte' => ['bientôt', null],
    'futur' => ['2027-01-01', null],
    'trop ancienne' => ['1970-01-01', null],
    'vide' => ['', null],
]);

it('accepts old birthdates when asked', function () {
    expect(ImportedDate::parse('12/05/1985', 1900))->toBe('1985-05-12');
    expect(ImportedDate::parse('31179', 1900))->toBe('1985-05-12');
});

it('imports an Excel serial purchase date correctly and skips unreadable ones', function () {
    $organization = Organization::factory()->create();
    Store::factory()->create(['organization_id' => $organization->id, 'name' => 'Adidas']);
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $csv = "telephone,boutique,nom,prix,date_achat\n".
        "77451288,Adidas,Aïcha,25000,46096\n".
        "66210347,Adidas,Brice,10000,pas une date\n";

    $this->actingAs($user)->post('/purchases', ['file' => UploadedFile::fake()->createWithContent('achats.csv', $csv)])
        ->assertSessionHas('success', fn ($message) => str_contains($message, '1 achat(s)') && str_contains($message, '1 ligne(s) ignorée(s)'));

    expect(Purchase::sole()->purchased_at->toDateString())->toBe('2026-03-15');
});

it('repairs purchases saved on 01/01/1970 by the old import, only with --force', function () {
    $store = Store::factory()->create();
    $contact = Contact::factory()->create(['store_id' => $store->id]);
    $purchase = Purchase::factory()->create(['contact_id' => $contact->id, 'store_id' => $store->id, 'purchased_at' => now()->subDay()]);
    // Ce que l'ancien import enregistrait pour la série Excel 46096.
    DB::table('purchases')->where('id', $purchase->id)->update([
        'purchased_at' => Carbon::createFromTimestamp(46096, config('app.timezone'))->format('Y-m-d H:i:s'),
    ]);

    $this->artisan('crm:repair-purchase-dates')->assertSuccessful();
    expect($purchase->fresh()->purchased_at->year)->toBe(1970);

    $this->artisan('crm:repair-purchase-dates --force')->assertSuccessful();
    expect($purchase->fresh()->purchased_at->toDateString())->toBe('2026-03-15');
    expect($contact->customer->fresh()->last_purchase_at->toDateString())->toBe('2026-03-15');
});
